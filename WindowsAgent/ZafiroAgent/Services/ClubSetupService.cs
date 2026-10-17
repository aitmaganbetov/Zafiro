using System;
using System.Collections.Generic;
using System.Globalization;
using System.Text.Json;
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

        /// <summary>
        /// Профиль клиента в бездисковом образе снят с другого ПК: номер и карточка ПК в нём — того ПК.
        /// Если сохранённый идентификатор (MAC) не этого ПК, привязка к карточке сбрасывается,
        /// а heartbeat заново определяет ПК по его MAC.
        /// </summary>
        public void ForgetAnotherPc(string? deviceId)
        {
            if (string.IsNullOrEmpty(deviceId)) return;
            var cfg = _config.Load();
            if (string.IsNullOrEmpty(cfg.DeviceId) || string.Equals(cfg.DeviceId, deviceId, StringComparison.OrdinalIgnoreCase)) return;

            _config.Update(c =>
            {
                c.ComputerId = string.Empty;
                c.ComputerNumber = string.Empty;
                c.Zone = string.Empty;
                c.DeviceId = string.Empty;
            });
            SafeLog.Write("[Setup] saved computer belongs to another PC (boot image): binding reset");
        }

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
        /// <param name="macAddress">MAC активного адаптера — по нему сервер отличает ПК одного образа.</param>
        public async Task<ApiResult<ComputerInfo>> ClaimComputerAsync(ClubSession session, string? deviceId, CancellationToken cancellationToken, bool claim = true, string? macAddress = null)
        {
            var result = await _api.ResolveComputerAsync(session, _deviceName, deviceId, cancellationToken, claim, macAddress).ConfigureAwait(false);
            if (!result.Success && result.Failure == ApiFailure.ComputerNotFound)
                result = await _api.RegisterComputerAsync(session, _deviceName, deviceId, macAddress, cancellationToken, claim).ConfigureAwait(false);
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

        /// <summary>
        /// Вход с телефона: новый QR-код этого ПК (по токену устройства). Forbidden — вход по
        /// QR-коду выключен в клубе; без токена устройства (ПК не подключён) — DeviceTokenInvalid.
        /// </summary>
        public async Task<ApiResult<QrLoginCode>> StartQrLoginAsync(CancellationToken cancellationToken)
        {
            var result = await _api.PostWithDeviceTokenAsync(ZafiroApiClient.QrLoginEndpoint, new { }, await DeviceTokenAsync(cancellationToken).ConfigureAwait(false), cancellationToken).ConfigureAwait(false);
            if (!result.Success) return ApiResult<QrLoginCode>.Fail(result.Failure, result.Message);

            var root = result.Value;
            if (!root.TryGetProperty("id", out var id) || id.ValueKind != JsonValueKind.String ||
                !root.TryGetProperty("url", out var url) || url.ValueKind != JsonValueKind.String ||
                !ZafiroApiClient.TryValidateHttpsEndpoint(url.GetString(), out var link))
                return ApiResult<QrLoginCode>.Fail(ApiFailure.InvalidResponse, L.T("Сервер Zafiro вернул неожиданный ответ."));

            var lifetime = root.TryGetProperty("lifetime_seconds", out var seconds) && seconds.TryGetInt32(out var s) && s > 0 ? s : 300;
            var interval = root.TryGetProperty("poll_interval_seconds", out var every) && every.TryGetInt32(out var e) && e >= 2 ? e : 3;
            return ApiResult<QrLoginCode>.Ok(new QrLoginCode(id.GetString()!, link, TimeSpan.FromSeconds(lifetime), TimeSpan.FromSeconds(interval)));
        }

        /// <summary>
        /// Подтвердил ли клиент вход на телефоне. Ok(null) — ещё нет; Ok(session) — клиент вошёл
        /// (токен сохранён, как при входе по паролю); Fail(Expired…) — нужен новый QR-код.
        /// </summary>
        public async Task<ApiResult<ClubSession?>> PollQrLoginAsync(string id, CancellationToken cancellationToken)
        {
            var payload = new Dictionary<string, object?> { ["id"] = id, ["device_name"] = _deviceName };
            var result = await _api.PostWithDeviceTokenAsync(ZafiroApiClient.QrLoginPollEndpoint, payload, await DeviceTokenAsync(cancellationToken).ConfigureAwait(false), cancellationToken).ConfigureAwait(false);
            if (!result.Success) return ApiResult<ClubSession?>.Fail(result.Failure, result.Message);

            var status = result.Value.TryGetProperty("status", out var s) && s.ValueKind == JsonValueKind.String ? s.GetString() : null;
            if (status == "pending") return ApiResult<ClubSession?>.Ok(null);
            if (status != "approved") return ApiResult<ClubSession?>.Fail(ApiFailure.TokenExpired, L.T("QR-код устарел."));

            var cfg = _config.Load();
            if (!long.TryParse(cfg.ClubId, NumberStyles.None, CultureInfo.InvariantCulture, out var clubId) ||
                !ZafiroApiClient.TryValidateHttpsEndpoint(cfg.ApiBaseUrl, out var apiBase))
                return ApiResult<ClubSession?>.Fail(ApiFailure.InvalidEndpoint, L.T("Сохранённые настройки клуба повреждены. Настройте клуб заново."));

            var login = _api.ReadQrLogin(result.Value, clubId, cfg.ClubName);
            if (!login.Success) return ApiResult<ClubSession?>.Fail(login.Failure, login.Message);

            _config.Update(current => ApplyToken(current, login.Value!));
            return ApiResult<ClubSession?>.Ok(new ClubSession(clubId, cfg.ClubName, apiBase, login.Value!.Token, login.Value.ExpiresAtUtc));
        }

        /// <summary>
        /// Есть ли на ПК гостевая сессия (кассир подключил тариф без аккаунта). Пустой heartbeat
        /// с токеном устройства: сервер отвечает текущей сессией ПК. Ok(null) — гостевой нет.
        /// </summary>
        public async Task<ApiResult<GuestSession?>> CheckGuestSessionAsync(CancellationToken cancellationToken)
        {
            // Пока сервер не знает о новой загрузке Windows, запрос несёт этот сигнал: старая гостевая
            // сессия сбрасывается раньше, чем окно входа успело бы её показать.
            var freshBoot = Infrastructure.FreshBoot.Pending;
            object body = freshBoot ? new { fresh_boot = true } : new { };
            var result = await _api.PostWithDeviceTokenAsync(ZafiroApiClient.AgentHeartbeatEndpoint, body, await DeviceTokenAsync(cancellationToken).ConfigureAwait(false), cancellationToken).ConfigureAwait(false);
            if (!result.Success) return ApiResult<GuestSession?>.Fail(result.Failure, result.Message);
            if (freshBoot) Infrastructure.FreshBoot.Reported();

            var root = result.Value;
            RememberComputer(root);
            var offset = root.TryGetProperty("server_time", out var serverTime) && serverTime.ValueKind == JsonValueKind.String &&
                DateTimeOffset.TryParse(serverTime.GetString(), CultureInfo.InvariantCulture, DateTimeStyles.AssumeUniversal, out var now)
                ? now.ToUniversalTime() - DateTimeOffset.UtcNow
                : TimeSpan.Zero;
            if (!root.TryGetProperty("session", out var session) || session.ValueKind != JsonValueKind.Object ||
                !session.TryGetProperty("guest", out var guest) || guest.ValueKind != JsonValueKind.True ||
                !session.TryGetProperty("id", out var id) || id.ValueKind != JsonValueKind.String ||
                !session.TryGetProperty("ends_at", out var endsAt) || endsAt.ValueKind != JsonValueKind.String ||
                !DateTimeOffset.TryParse(endsAt.GetString(), CultureInfo.InvariantCulture, DateTimeStyles.AssumeUniversal, out var ends))
                return ApiResult<GuestSession?>.Ok(null);

            return ApiResult<GuestSession?>.Ok(new GuestSession(id.GetString()!, ends.ToUniversalTime(), offset));
        }

        /// <summary>
        /// Регистрация клиента на этом ПК (по токену устройства): новый аккаунт клиента клуба,
        /// сразу вошедший здесь — токен сохраняется, как после входа по паролю.
        /// </summary>
        public async Task<ApiResult<ClubSession>> RegisterAsync(string name, string phone, string password, CancellationToken cancellationToken)
        {
            var payload = new Dictionary<string, object?> { ["name"] = name, ["phone"] = phone, ["password"] = password, ["device_name"] = _deviceName };
            var result = await _api.PostWithDeviceTokenAsync(ZafiroApiClient.AgentRegisterEndpoint, payload, await DeviceTokenAsync(cancellationToken).ConfigureAwait(false), cancellationToken).ConfigureAwait(false);
            if (!result.Success) return ApiResult<ClubSession>.Fail(result.Failure, result.Message);

            var cfg = _config.Load();
            if (!long.TryParse(cfg.ClubId, NumberStyles.None, CultureInfo.InvariantCulture, out var clubId) ||
                !ZafiroApiClient.TryValidateHttpsEndpoint(cfg.ApiBaseUrl, out var apiBase))
                return ApiResult<ClubSession>.Fail(ApiFailure.InvalidEndpoint, L.T("Сохранённые настройки клуба повреждены. Настройте клуб заново."));

            var login = _api.ReadQrLogin(result.Value, clubId, cfg.ClubName);
            if (!login.Success) return ApiResult<ClubSession>.Fail(login.Failure, login.Message);

            _config.Update(current => ApplyToken(current, login.Value!));
            return ApiResult<ClubSession>.Ok(new ClubSession(clubId, cfg.ClubName, apiBase, login.Value!.Token, login.Value.ExpiresAtUtc));
        }

        /// <summary>
        /// Сообщает серверу о новой загрузке Windows (гостевая сессия ПК сбрасывается). Повтор каждые
        /// 15 с, пока сервер недоступен, но не дольше 10 минут; ПК без токена устройства не сообщает.
        /// </summary>
        public async Task ReportFreshBootAsync(CancellationToken cancellationToken)
        {
            for (var attempt = 0; attempt < 40 && Infrastructure.FreshBoot.Pending; attempt++)
            {
                try
                {
                    var token = await DeviceTokenAsync(cancellationToken).ConfigureAwait(false);
                    if (string.IsNullOrEmpty(token)) return;
                    var result = await _api.PostWithDeviceTokenAsync(ZafiroApiClient.AgentHeartbeatEndpoint, new { fresh_boot = true }, token, cancellationToken).ConfigureAwait(false);
                    if (result.Success)
                    {
                        Infrastructure.FreshBoot.Reported();
                        RememberComputer(result.Value);
                        return;
                    }
                    if (result.Failure is ApiFailure.DeviceTokenInvalid) return;
                }
                catch (Exception ex) when (ex is System.Net.Http.HttpRequestException or TaskCanceledException or InvalidOperationException)
                {
                    SafeLog.Write("[Boot] report failed: " + ex.GetType().Name);
                }

                await Task.Delay(TimeSpan.FromSeconds(15), cancellationToken).ConfigureAwait(false);
            }
        }

        /// <summary>
        /// Номер, id и зона ПК — по токену устройства, от сервера: ПК, получивший токен сам (бездисковый,
        /// с образа суперклиента), иначе показал бы «не задан» или номер суперклиента и покупал бы на чужой ПК.
        /// </summary>
        private void RememberComputer(JsonElement root)
        {
            if (!root.TryGetProperty("computer_id", out var id) || !id.TryGetInt64(out var computerId) || computerId <= 0) return;
            var number = root.TryGetProperty("number", out var n)
                ? n.ValueKind switch { JsonValueKind.String => n.GetString(), JsonValueKind.Number => n.GetRawText(), _ => null }
                : null;
            var zone = root.TryGetProperty("zone", out var z) && z.ValueKind == JsonValueKind.Object && z.TryGetProperty("name", out var name) && name.ValueKind == JsonValueKind.String
                ? name.GetString()
                : null;
            var idText = computerId.ToString(CultureInfo.InvariantCulture);
            var cfg = _config.Load();
            if (cfg.ComputerId == idText && cfg.ComputerNumber == (number ?? string.Empty) && cfg.Zone == (zone ?? string.Empty)) return;

            _config.Update(c =>
            {
                c.ComputerId = idText;
                c.ComputerNumber = number ?? string.Empty;
                c.Zone = zone ?? string.Empty;
            });
            SafeLog.Write("[Setup] computer identity updated from the server");
        }

        private Task<string?> DeviceTokenAsync(CancellationToken cancellationToken) =>
            Task.Run(() => DeviceTokens.Resolve(_secure.Unprotect(_config.Load().ProtectedDeviceToken)), cancellationToken);

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
