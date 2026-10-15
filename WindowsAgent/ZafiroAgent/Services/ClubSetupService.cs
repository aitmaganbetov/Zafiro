using System;
using System.Globalization;
using System.Threading;
using System.Threading.Tasks;
using ZafiroAgent.Configuration;
using ZafiroAgent.Models;
using ZafiroAgent.Security;
using ZafiroAgent.Localization;

namespace ZafiroAgent.Services
{
    /// <summary>
    /// Установка агента для клуба: resolve по ключу, вход и защищённое хранение настроек.
    /// Каждый вызов делает не более одной попытки и не переключает клуб автоматически.
    /// </summary>
    public sealed class ClubSetupService
    {
        private readonly ZafiroApiClient _api;
        private readonly ConfigurationService _config;
        private readonly SecureStorageService _secure;
        private readonly Func<DateTime> _utcNow;
        private readonly string _deviceName;
        private readonly SemaphoreSlim _gate = new(1, 1);

        public ClubSetupService(ZafiroApiClient api, ConfigurationService config, SecureStorageService secure, string? deviceName = null, Func<DateTime>? utcNow = null)
        {
            _api = api;
            _config = config;
            _secure = secure;
            _deviceName = string.IsNullOrWhiteSpace(deviceName) ? Environment.MachineName : deviceName;
            _utcNow = utcNow ?? (() => DateTime.UtcNow);
        }

        public bool IsClubConfigured => IsConfigured(_config.Load());

        public string ClubName => _config.Load().ClubName;

        public string ComputerId => _config.Load().ComputerId;

        public string ComputerNumber => _config.Load().ComputerNumber;

        public string Zone => _config.Load().Zone;

        public async Task<ApiResult<ClubSession>> SetupAsync(string clubKey, string phone, string password, CancellationToken cancellationToken)
        {
            await _gate.WaitAsync(cancellationToken).ConfigureAwait(false);
            try
            {
                if (IsClubConfigured)
                    return ApiResult<ClubSession>.Fail(ApiFailure.ClubAlreadyConfigured, L.T("Клуб уже настроен. Сбросьте настройку клуба, чтобы подключить другой."));

                var key = clubKey?.Trim() ?? string.Empty;
                SafeLog.Register(key);
                var resolved = await _api.ResolveClubAsync(key, cancellationToken).ConfigureAwait(false);
                if (!resolved.Success) return ApiResult<ClubSession>.Fail(resolved.Failure, resolved.Message);

                var club = resolved.Value!;
                var login = await _api.LoginAsync(club, key, phone, password, _deviceName, cancellationToken).ConfigureAwait(false);
                if (!login.Success) return ApiResult<ClubSession>.Fail(login.Failure, login.Message);

                var cfg = _config.Load();
                cfg.ClubId = club.ClubId.ToString(CultureInfo.InvariantCulture);
                cfg.ClubName = login.Value!.ClubName;
                cfg.ApiBaseUrl = club.ApiBaseUrl.AbsoluteUri;
                cfg.LoginEndpoint = club.LoginEndpoint.AbsoluteUri;
                // Если служба приняла ключ, в профиле клиента остаётся только метка: ключ знает служба.
                var keyInService = DeviceTokens.Vault is { } vault && vault.SetClubKey(key);
                if (DeviceTokens.Vault is not null && !keyInService)
                    SafeLog.Write("[Vault] club key was not accepted by the service");
                cfg.ProtectedClubKey = _secure.Protect(keyInService ? DeviceTokens.VaultMarker : key);
                ApplyToken(cfg, login.Value);
                _config.Save(cfg);

                return ApiResult<ClubSession>.Ok(new ClubSession(club.ClubId, cfg.ClubName, club.ApiBaseUrl, login.Value.Token, login.Value.ExpiresAtUtc));
            }
            finally
            {
                _gate.Release();
            }
        }

        /// <summary>Повторный вход для уже сохранённого клуба (например, после истечения токена).</summary>
        public async Task<ApiResult<ClubSession>> ReloginAsync(string phone, string password, CancellationToken cancellationToken)
        {
            await _gate.WaitAsync(cancellationToken).ConfigureAwait(false);
            try
            {
                var cfg = _config.Load();
                if (!IsConfigured(cfg))
                    return ApiResult<ClubSession>.Fail(ApiFailure.NotConfigured, L.T("Клуб не настроен. Введите ключ установки."));

                var key = _secure.Unprotect(cfg.ProtectedClubKey);
                SafeLog.Register(key);
                if (string.IsNullOrEmpty(key))
                    return ApiResult<ClubSession>.Fail(ApiFailure.NotConfigured, L.T("Сохранённый ключ установки недоступен. Настройте клуб заново."));

                var keyInService = key == DeviceTokens.VaultMarker;
                if (keyInService && DeviceTokens.Vault is null)
                    return ApiResult<ClubSession>.Fail(ApiFailure.ServiceUnavailable, L.T("Ключ установки хранится в службе Zafiro Agent, а она не установлена. Переустановите агент."));

                if (!long.TryParse(cfg.ClubId, NumberStyles.None, CultureInfo.InvariantCulture, out var savedClubId) ||
                    !ZafiroApiClient.TryValidateHttpsEndpoint(cfg.ApiBaseUrl, out var apiBase) ||
                    !ZafiroApiClient.TryValidateHttpsEndpoint(cfg.LoginEndpoint, out var loginEndpoint))
                    return ApiResult<ClubSession>.Fail(ApiFailure.InvalidEndpoint, L.T("Сохранённые настройки клуба повреждены. Настройте клуб заново."));

                // Сначала проверяем, что ключ не отозван и по-прежнему принадлежит тому же клубу.
                // Ключ у службы — его проверяет сервер при входе, а клуб — по club_id в ответе.
                if (!keyInService)
                {
                    var resolved = await _api.ResolveClubAsync(key, cancellationToken).ConfigureAwait(false);
                    if (!resolved.Success)
                    {
                        return resolved.Failure == ApiFailure.InvalidKey
                            ? ApiResult<ClubSession>.Fail(ApiFailure.KeyRevoked, L.T("Ключ установки недействителен или отозван. Обратитесь к администратору клуба."))
                            : ApiResult<ClubSession>.Fail(resolved.Failure, resolved.Message);
                    }

                    if (resolved.Value!.ClubId != savedClubId)
                        return ApiResult<ClubSession>.Fail(ApiFailure.ClubMismatch, L.T("Ключ установки теперь относится к другому клубу. Автоматическое переключение запрещено."));
                }

                var club = new ClubInfo(savedClubId, cfg.ClubName, apiBase, loginEndpoint);
                var login = await _api.LoginAsync(club, key, phone, password, _deviceName, cancellationToken).ConfigureAwait(false);
                if (!login.Success) return ApiResult<ClubSession>.Fail(login.Failure, login.Message);

                // Настройки читаются заново: за время входа heartbeat мог их изменить.
                _config.Update(current => ApplyToken(current, login.Value!));

                return ApiResult<ClubSession>.Ok(new ClubSession(savedClubId, cfg.ClubName, apiBase, login.Value!.Token, login.Value.ExpiresAtUtc));
            }
            finally
            {
                _gate.Release();
            }
        }

        /// <summary>Возвращает сохранённую сессию либо TokenExpired, если нужен повторный вход.</summary>
        public ApiResult<ClubSession> LoadSession()
        {
            var cfg = _config.Load();
            if (!IsConfigured(cfg))
                return ApiResult<ClubSession>.Fail(ApiFailure.NotConfigured, L.T("Клуб не настроен. Введите ключ установки."));

            if (!long.TryParse(cfg.ClubId, NumberStyles.None, CultureInfo.InvariantCulture, out var clubId) ||
                !ZafiroApiClient.TryValidateHttpsEndpoint(cfg.ApiBaseUrl, out var apiBase))
                return ApiResult<ClubSession>.Fail(ApiFailure.InvalidEndpoint, L.T("Сохранённые настройки клуба повреждены. Настройте клуб заново."));

            var token = _secure.Unprotect(cfg.ProtectedApiToken);
            SafeLog.Register(token);
            if (string.IsNullOrEmpty(token) || cfg.TokenExpiresAtUtc is not { } expires || expires <= _utcNow())
                return ApiResult<ClubSession>.Fail(ApiFailure.TokenExpired, L.T("Срок действия входа истёк. Выполните вход повторно."));

            return ApiResult<ClubSession>.Ok(new ClubSession(clubId, cfg.ClubName, apiBase, token, expires));
        }

        /// <summary>
        /// Привязывает этот ПК к клубу от имени вошедшего пользователя. Сотруднику сервер
        /// выдаёт токен устройства (он сохраняется защищённым) — по нему агент работает
        /// дальше, а сам сотрудник выходит. Клиенту токен не выдаётся: AgentToken = null.
        /// </summary>
        /// <param name="claim">false — ПК уже подключён: только определить его, токен не нужен.</param>
        public async Task<ApiResult<ComputerInfo>> ClaimComputerAsync(ClubSession session, string? deviceId, CancellationToken cancellationToken, bool claim = true)
        {
            var result = await _api.ResolveComputerAsync(session, _deviceName, deviceId, cancellationToken, claim).ConfigureAwait(false);
            if (!result.Success && result.Failure == ApiFailure.ComputerNotFound)
                result = await _api.RegisterComputerAsync(session, _deviceName, deviceId, null, cancellationToken, claim).ConfigureAwait(false);
            if (!result.Success) return result;

            var info = result.Value!;
            SafeLog.Register(info.AgentToken);
            // Если установлена служба, токен уходит к ней, а у агента остаётся только метка.
            var keep = string.IsNullOrEmpty(info.AgentToken) ? null : await DeviceTokens.KeepAsync(info.AgentToken, cancellationToken).ConfigureAwait(false);
            if (keep is { Persist: false })
                return ApiResult<ComputerInfo>.Fail(ApiFailure.ServiceUnavailable, L.T("Служба Zafiro Agent не приняла токен ПК. Проверьте, что служба запущена, и повторите вход."));

            _config.Update(cfg =>
            {
                cfg.ComputerId = info.ComputerId.ToString(CultureInfo.InvariantCulture);
                cfg.ComputerNumber = info.Number;
                cfg.Zone = info.ZoneName ?? string.Empty;
                cfg.DeviceName = _deviceName;
                cfg.DeviceId = deviceId ?? string.Empty;
                if (keep is not null)
                    cfg.ProtectedDeviceToken = _secure.Protect(keep.Use);
            });

            return result;
        }

        /// <summary>У ПК есть токен устройства (у агента или в службе): ПК уже подключён сотрудником.</summary>
        public bool HasDeviceToken =>
            DeviceTokens.Resolve(_secure.Unprotect(_config.Load().ProtectedDeviceToken)) is not null;

        /// <summary>
        /// Выход с отзывом токена на сервере (не дольше timeout): скопированный с ПК токен
        /// после выхода больше не работает. Локально токен удаляется в любом случае.
        /// </summary>
        public async Task SignOutAsync(TimeSpan timeout)
        {
            var session = LoadSession();
            if (session.Success)
            {
                using var cts = new CancellationTokenSource(timeout);
                try
                {
                    using var response = await _api.SendAuthorizedAsync(session.Value!, System.Net.Http.HttpMethod.Post, "auth/logout", null, cts.Token).ConfigureAwait(false);
                }
                catch (Exception ex)
                {
                    SafeLog.Write("[SignOut] token was not revoked on the server: " + ex.Message);
                }
            }

            Logout();
        }

        /// <summary>Выход пользователя: удаляет токен, клуб и ключ установки остаются.</summary>
        public void Logout()
        {
            _config.Update(cfg =>
            {
                cfg.ProtectedApiToken = string.Empty;
                cfg.TokenExpiresAtUtc = null;
            });
        }

        /// <summary>Сброс настройки клуба — только по явному решению пользователя.</summary>
        public void ResetClub()
        {
            _config.Update(cfg =>
            {
                cfg.ClubId = string.Empty;
                cfg.ClubName = string.Empty;
                cfg.ApiBaseUrl = string.Empty;
                cfg.LoginEndpoint = string.Empty;
                cfg.ProtectedClubKey = string.Empty;
                cfg.ProtectedApiToken = string.Empty;
                cfg.TokenExpiresAtUtc = null;
            });
        }

        private void ApplyToken(AgentConfig cfg, ClubLogin login)
        {
            SafeLog.Register(login.Token);
            cfg.ProtectedApiToken = _secure.Protect(login.Token);
            cfg.TokenExpiresAtUtc = login.ExpiresAtUtc;
        }

        private static bool IsConfigured(AgentConfig cfg) =>
            !string.IsNullOrWhiteSpace(cfg.ClubId) && !string.IsNullOrEmpty(cfg.ProtectedClubKey);
    }
}
