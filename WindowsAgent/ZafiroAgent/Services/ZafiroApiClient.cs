using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Encodings.Web;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
using ZafiroAgent.Models;
using ZafiroAgent.Localization;

namespace ZafiroAgent.Services
{
    /// <summary>
    /// Единственный клиент API Zafiro: определение клуба, вход и авторизованные запросы.
    /// Не пишет в логи и не включает в сообщения ключ, пароль, токен и тело ответа сервера.
    /// </summary>
    public sealed class ZafiroApiClient
    {
        public const string ApiHost = "zafiro.kz";
        public static string ComputerNotRegistered => L.T("ПК не зарегистрирован в клубе. Добавьте его в зоне клуба в панели или исправьте имя ПК.");
        public const string ComputerNotFoundCode = "computer_not_found";
        public static string EndpointMissing => L.T("Сервер Zafiro не поддерживает этот запрос (маршрут не найден). Обновите backend Zafiro.");
        public static string RegisterForbidden => L.T("Недостаточно прав, чтобы добавить этот ПК в клуб. Попросите администратора добавить его в зоне клуба.");
        public static readonly Uri ResolveEndpoint = new("https://zafiro.kz/api/clubs/resolve");
        public static readonly Uri AgentLoginEndpoint = new("https://zafiro.kz/api/agent/user/login");
        public static readonly Uri ExitPinEndpoint = new("https://zafiro.kz/api/agent/exit-pin");

        private static readonly TimeSpan DefaultTokenLifetime = TimeSpan.FromDays(30);
        private static readonly JsonSerializerOptions JsonOptions = new() { Encoder = JavaScriptEncoder.UnsafeRelaxedJsonEscaping };

        private readonly HttpClient _http;
        private readonly Func<DateTime> _utcNow;

        public ZafiroApiClient(HttpClient http, Func<DateTime>? utcNow = null)
        {
            _http = http;
            _utcNow = utcNow ?? (() => DateTime.UtcNow);
        }

        // Редиректы выключены, чтобы ключ и пароль не ушли на другой хост; проверка TLS стандартная.
        // Запросы с меткой токена ПК уходят через службу (DeviceVaultHandler).
        public static HttpClient CreateHttpClient() =>
            new(new LocaleHandler(new DeviceVaultHandler(new SocketsHttpHandler { AllowAutoRedirect = false }))) { Timeout = TimeSpan.FromSeconds(30) };

        public static readonly Uri TranslationsEndpoint = new("https://zafiro.kz/api/agent/translations/");

        /// <summary>
        /// Тексты агента с правками суперадмина (без токена: они не секретны). null — нет связи
        /// или ответ не подходит; тогда остаются встроенные тексты.
        /// </summary>
        public async Task<L.TextPack?> GetAgentTranslationsAsync(string locale, CancellationToken cancellationToken)
        {
            if (string.IsNullOrEmpty(locale) || !locale.All(c => c is >= 'a' and <= 'z') || locale.Length > 8) return null;

            try
            {
                using var response = await _http.GetAsync(new Uri(TranslationsEndpoint, locale), cancellationToken).ConfigureAwait(false);
                if (!response.IsSuccessStatusCode || response.Content.Headers.ContentLength > 1024 * 1024) return null;
                var pack = L.Parse(await response.Content.ReadAsStringAsync(cancellationToken).ConfigureAwait(false));
                return pack?.Locale == locale ? pack : null;
            }
            catch (Exception ex) when (ex is HttpRequestException || (ex is OperationCanceledException && !cancellationToken.IsCancellationRequested))
            {
                return null;
            }
        }

        /// <summary>Клиент без службы — для самой службы.</summary>
        public static HttpClient CreateDirectHttpClient() =>
            new(new SocketsHttpHandler { AllowAutoRedirect = false }) { Timeout = TimeSpan.FromSeconds(30) };

        public static bool TryValidateHttpsEndpoint(string? value, out Uri uri)
        {
            uri = null!;
            if (!Uri.TryCreate(value, UriKind.Absolute, out var parsed)) return false;
            if (parsed.Scheme != Uri.UriSchemeHttps) return false;
            if (!string.Equals(parsed.Host, ApiHost, StringComparison.OrdinalIgnoreCase)) return false;
            if (!parsed.IsDefaultPort || !string.IsNullOrEmpty(parsed.UserInfo) || !string.IsNullOrEmpty(parsed.Fragment)) return false;
            uri = parsed;
            return true;
        }

        public async Task<ApiResult<ClubInfo>> ResolveClubAsync(string clubKey, CancellationToken cancellationToken)
        {
            if (string.IsNullOrWhiteSpace(clubKey))
                return ApiResult<ClubInfo>.Fail(ApiFailure.InvalidInput, L.T("Введите ключ установки."));

            var response = await PostJsonAsync(ResolveEndpoint, new Dictionary<string, object?> { ["club_key"] = clubKey.Trim() }, isLogin: false, cancellationToken).ConfigureAwait(false);
            if (!response.Success) return ApiResult<ClubInfo>.Fail(response.Failure, response.Message);

            var root = response.Value;
            if (!TryGetLong(root, "club_id", out var clubId) || clubId <= 0 || !TryGetString(root, "club_name", out var clubName))
                return InvalidResponse<ClubInfo>();

            if (!TryGetString(root, "api_base_url", out var apiBase) || !TryGetString(root, "login_endpoint", out var loginEndpoint))
                return InvalidResponse<ClubInfo>();

            if (!TryValidateHttpsEndpoint(apiBase, out var apiBaseUri) || !TryValidateHttpsEndpoint(loginEndpoint, out var loginUri))
                return ApiResult<ClubInfo>.Fail(ApiFailure.InvalidEndpoint, L.T("Сервер вернул недопустимый адрес API. Работа остановлена."));

            return ApiResult<ClubInfo>.Ok(new ClubInfo(clubId, clubName, apiBaseUri, loginUri));
        }

        public async Task<ApiResult<ClubLogin>> LoginAsync(ClubInfo club, string clubKey, string phone, string password, string deviceName, CancellationToken cancellationToken)
        {
            if (string.IsNullOrWhiteSpace(phone) || string.IsNullOrEmpty(password))
                return ApiResult<ClubLogin>.Fail(ApiFailure.InvalidInput, L.T("Введите телефон и пароль."));

            var payload = new Dictionary<string, object?>
            {
                ["phone"] = phone.Trim(),
                ["password"] = password,
                ["device_name"] = deviceName,
                ["club_key"] = clubKey,
                ["club_id"] = club.ClubId
            };

            // Ключ клуба у службы: запрос идёт через неё, и ключ подставляет она.
            var viaService = clubKey == DeviceTokens.VaultMarker;
            var response = await PostJsonAsync(AgentLoginEndpoint, payload, isLogin: true, cancellationToken, viaService).ConfigureAwait(false);
            if (!viaService && !response.Success && response.Failure == ApiFailure.EndpointNotFound && club.LoginEndpoint != AgentLoginEndpoint)
                response = await PostJsonAsync(club.LoginEndpoint, payload, isLogin: true, cancellationToken).ConfigureAwait(false);
            if (!response.Success) return ApiResult<ClubLogin>.Fail(response.Failure, response.Message);

            var root = response.Value;
            var data = root.TryGetProperty("data", out var nested) && nested.ValueKind == JsonValueKind.Object ? nested : root;
            var token = TryGetString(data, "token", out var t) ? t : TryGetString(data, "access_token", out var at) ? at : null;
            if (token is null || token.Any(c => char.IsWhiteSpace(c) || char.IsControl(c)))
                return InvalidResponse<ClubLogin>();

            long? responseClubId = null;
            if (TryGetLong(data, "club_id", out var dataClubId) || TryGetLong(root, "club_id", out dataClubId))
                responseClubId = dataClubId;
            if (responseClubId is { } id && id != club.ClubId)
                return ApiResult<ClubLogin>.Fail(ApiFailure.ClubMismatch, L.T("Сервер подтвердил другой клуб. Вход отменён, агент не запущен."));

            var confirmedName = TryGetString(data, "club_name", out var name)
                ? name
                : TryGetString(root, "club_name", out name) ? name : club.ClubName;

            return ApiResult<ClubLogin>.Ok(new ClubLogin(token, responseClubId ?? club.ClubId, confirmedName, ReadExpiry(data)));
        }

        /// <summary>Проверяет PIN-код сотрудника на сервере (по токену устройства этого ПК).</summary>
        public async Task<ApiResult<bool>> VerifyExitPinAsync(string? deviceToken, string pin, CancellationToken cancellationToken, string? userToken = null)
        {
            if (string.IsNullOrEmpty(deviceToken))
                return ApiResult<bool>.Fail(ApiFailure.DeviceTokenInvalid, L.T("ПК ещё не настроен сотрудником клуба: PIN проверить нельзя."));
            if (string.IsNullOrWhiteSpace(pin))
                return ApiResult<bool>.Fail(ApiFailure.InvalidInput, L.T("Введите PIN-код."));

            try
            {
                using var request = new HttpRequestMessage(HttpMethod.Post, ExitPinEndpoint) { Content = JsonBody(new Dictionary<string, object?> { ["pin"] = pin }) };
                request.Headers.Add("X-Agent-Token", deviceToken);
                // Токен вошедшего пользователя — только чтобы сервер записал в журнал, при ком закрыли агент.
                if (!string.IsNullOrEmpty(userToken))
                    request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", userToken);
                using var response = await _http.SendAsync(request, cancellationToken).ConfigureAwait(false);

                return response.StatusCode switch
                {
                    HttpStatusCode.OK => ApiResult<bool>.Ok(true),
                    HttpStatusCode.UnprocessableEntity => ApiResult<bool>.Fail(ApiFailure.InvalidCredentials, L.T("Неверный PIN-код.")),
                    HttpStatusCode.Conflict => ApiResult<bool>.Fail(ApiFailure.NotConfigured, L.T("PIN-код не задан. Задайте его в настройках клуба в панели Zafiro.")),
                    HttpStatusCode.TooManyRequests => ApiResult<bool>.Fail(ApiFailure.Forbidden, L.T("Слишком много попыток. Подождите 5 минут.")),
                    HttpStatusCode.Unauthorized => ApiResult<bool>.Fail(ApiFailure.DeviceTokenInvalid, L.T("Токен устройства недействителен: сотрудник должен заново войти на этом ПК.")),
                    _ => ApiResult<bool>.Fail(ApiFailure.Unknown, L.T("Сервер Zafiro вернул ошибку (:code).", ("code", (int)response.StatusCode)))
                };
            }
            catch (Exception ex) when (ex is HttpRequestException || (ex is OperationCanceledException && !cancellationToken.IsCancellationRequested))
            {
                return ApiResult<bool>.Fail(ApiFailure.Network, L.T("Нет связи с сервером Zafiro: PIN проверить нельзя."));
            }
        }

        /// <summary>Запрос относительно api_base_url с Bearer-токеном. 401/402/403 приводят к ZafiroApiException.</summary>
        public async Task<HttpResponseMessage> SendAuthorizedAsync(ClubSession session, HttpMethod method, string relativePath, HttpContent? content, CancellationToken cancellationToken, string? deviceToken = null)
        {
            var baseUri = WithTrailingSlash(session.ApiBaseUrl);
            if (!TryValidateHttpsEndpoint(baseUri.AbsoluteUri, out _) ||
                !Uri.TryCreate(relativePath, UriKind.Relative, out var relative) ||
                !Uri.TryCreate(baseUri, relative, out var target) ||
                !TryValidateHttpsEndpoint(target.AbsoluteUri, out _) ||
                !target.AbsolutePath.StartsWith(baseUri.AbsolutePath, StringComparison.Ordinal))
            {
                throw new ZafiroApiException(ApiFailure.InvalidEndpoint, L.T("Недопустимый адрес запроса к API Zafiro."));
            }

            using var request = new HttpRequestMessage(method, target) { Content = content };
            if (deviceToken is null)
                request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", session.Token);
            else
            {
                request.Headers.Add("X-Agent-Token", deviceToken);
                // Без службы токен ПК отправляет сам агент — вместе с UUID этого ПК. Через службу
                // UUID подставляет она: агенту в сеансе клиента здесь не доверяют.
                if (deviceToken != DeviceTokens.VaultMarker && DeviceInfo.StableId is { Length: > 0 } deviceId)
                    request.Headers.Add(ZafiroAgent.Service.VaultProtocol.DeviceHeader, deviceId);
            }

            HttpResponseMessage response;
            try
            {
                response = await _http.SendAsync(request, cancellationToken).ConfigureAwait(false);
            }
            catch (Exception ex) when (ex is HttpRequestException || (ex is OperationCanceledException && !cancellationToken.IsCancellationRequested))
            {
                throw new ZafiroApiException(ApiFailure.Network, L.T("Не удалось связаться с сервером Zafiro."));
            }

            var failure = response.StatusCode switch
            {
                HttpStatusCode.Unauthorized when deviceToken is not null => (ApiFailure?)ApiFailure.DeviceTokenInvalid,
                HttpStatusCode.Unauthorized => ApiFailure.TokenExpired,
                HttpStatusCode.PaymentRequired => ApiFailure.SubscriptionInactive,
                HttpStatusCode.Forbidden => ApiFailure.Forbidden,
                _ => null
            };

            if (failure is null) return response;

            response.Dispose();
            throw new ZafiroApiException(failure.Value, failure.Value switch
            {
                ApiFailure.TokenExpired => L.T("Срок действия входа истёк. Выполните вход повторно."),
                ApiFailure.DeviceTokenInvalid => L.T("Токен устройства недействителен: сотрудник должен заново войти на этом ПК."),
                ApiFailure.SubscriptionInactive => L.T("Подписка клуба неактивна. Обратитесь к администратору клуба."),
                _ => L.T("Доступ запрещён: пользователь не состоит в клубе либо club_id не совпадает. Работа с текущей конфигурацией остановлена.")
            });
        }

        private async Task<ApiResult<JsonElement>> PostJsonAsync(Uri url, object payload, bool isLogin, CancellationToken cancellationToken, bool viaService = false)
        {
            try
            {
                using var request = new HttpRequestMessage(HttpMethod.Post, url)
                {
                    Content = new StringContent(JsonSerializer.Serialize(payload, JsonOptions), Encoding.UTF8, "application/json")
                };
                // Метка вместо токена: DeviceVaultHandler отправит запрос через службу.
                if (viaService) request.Headers.Add("X-Agent-Token", DeviceTokens.VaultMarker);
                using var response = await _http.SendAsync(request, cancellationToken).ConfigureAwait(false);

                if (!response.IsSuccessStatusCode)
                    return MapError(response.StatusCode, isLogin);

                var text = await response.Content.ReadAsStringAsync(cancellationToken).ConfigureAwait(false);
                using var document = JsonDocument.Parse(text);
                return document.RootElement.ValueKind == JsonValueKind.Object
                    ? ApiResult<JsonElement>.Ok(document.RootElement.Clone())
                    : InvalidResponse<JsonElement>();
            }
            catch (JsonException)
            {
                return InvalidResponse<JsonElement>();
            }
            catch (Exception ex) when (ex is HttpRequestException || (ex is OperationCanceledException && !cancellationToken.IsCancellationRequested))
            {
                return ApiResult<JsonElement>.Fail(ApiFailure.Network, L.T("Не удалось связаться с сервером Zafiro. Проверьте подключение и повторите попытку."));
            }
        }

        private static ApiResult<JsonElement> MapError(HttpStatusCode status, bool isLogin) => status switch
        {
            HttpStatusCode.NotFound when isLogin => ApiResult<JsonElement>.Fail(ApiFailure.EndpointNotFound, EndpointMissing),
            HttpStatusCode.Unauthorized => isLogin
                ? ApiResult<JsonElement>.Fail(ApiFailure.InvalidCredentials, L.T("Неверный номер или пароль."))
                : ApiResult<JsonElement>.Fail(ApiFailure.InvalidKey, L.T("Неверный ключ установки.")),
            HttpStatusCode.PaymentRequired => ApiResult<JsonElement>.Fail(ApiFailure.SubscriptionInactive, L.T("Подписка клуба неактивна. Обратитесь к администратору клуба.")),
            HttpStatusCode.Forbidden => ApiResult<JsonElement>.Fail(ApiFailure.Forbidden, isLogin
                ? L.T("Пользователь не состоит в этом клубе, либо club_id не совпадает.")
                : L.T("Доступ запрещён.")),
            _ => ApiResult<JsonElement>.Fail(ApiFailure.Unknown, L.T("Сервер Zafiro вернул ошибку (:code). Повторите попытку позже.", ("code", (int)status)))
        };

        private static ApiResult<T> InvalidResponse<T>() =>
            ApiResult<T>.Fail(ApiFailure.InvalidResponse, L.T("Сервер Zafiro вернул неожиданный ответ."));

        private DateTime ReadExpiry(JsonElement root)
        {
            if (TryGetString(root, "expires_at", out var text) &&
                DateTimeOffset.TryParse(text, CultureInfo.InvariantCulture, DateTimeStyles.AssumeUniversal, out var at))
                return at.UtcDateTime;

            if (root.TryGetProperty("expires_in", out var seconds) && seconds.ValueKind == JsonValueKind.Number && seconds.TryGetInt64(out var s) && s > 0)
                return _utcNow().AddSeconds(s);

            return _utcNow().Add(DefaultTokenLifetime);
        }

        /// <summary>Определяет этот ПК в клубе по имени и идентификатору устройства.</summary>
        /// <param name="claim">
        /// true — получить токен устройства, если вошёл сотрудник (подключение ПК). Агент просит его,
        /// только пока токена нет: иначе каждый вход сотрудника выдавал бы новый токен.
        /// </param>
        public async Task<ApiResult<ComputerInfo>> ResolveComputerAsync(ClubSession session, string deviceName, string? deviceId, CancellationToken cancellationToken, bool claim = false)
        {
            var payload = new Dictionary<string, object?> { ["device_name"] = deviceName, ["claim"] = claim };
            if (!string.IsNullOrWhiteSpace(deviceId)) payload["device_id"] = deviceId;
            using var content = JsonBody(payload);

            HttpResponseMessage response;
            try
            {
                response = await SendAuthorizedAsync(session, HttpMethod.Post, "club-computers/resolve", content, cancellationToken).ConfigureAwait(false);
            }
            catch (ZafiroApiException ex)
            {
                return ApiResult<ComputerInfo>.Fail(ex.Failure, ex.Message);
            }

            using (response)
            {
                // 404 с code=computer_not_found — ПК нет в клубе; любой другой 404 — нет маршрута.
                if (response.StatusCode == HttpStatusCode.NotFound)
                    return await ReadErrorCodeAsync(response, cancellationToken).ConfigureAwait(false) == ComputerNotFoundCode
                        ? ApiResult<ComputerInfo>.Fail(ApiFailure.ComputerNotFound, ComputerNotRegistered)
                        : ApiResult<ComputerInfo>.Fail(ApiFailure.EndpointNotFound, EndpointMissing);

                if (IsTemporaryFailure(response.StatusCode))
                    return ApiResult<ComputerInfo>.Fail(ApiFailure.Network, L.T("Сервер Zafiro временно недоступен."));

                if (!response.IsSuccessStatusCode)
                    return ApiResult<ComputerInfo>.Fail(ApiFailure.Unknown, L.T("Сервер Zafiro вернул ошибку (:code).", ("code", (int)response.StatusCode)));

                return await ParseComputerAsync(response, cancellationToken).ConfigureAwait(false);
            }
        }

        /// <summary>Регистрирует ПК в клубе токена (201 — создан, 200 — уже был). Вызывается только после resolve с computer_not_found.</summary>
        public async Task<ApiResult<ComputerInfo>> RegisterComputerAsync(ClubSession session, string deviceName, string? deviceId, string? macAddress, CancellationToken cancellationToken, bool claim = false)
        {
            var payload = new Dictionary<string, object?> { ["device_name"] = deviceName, ["claim"] = claim };
            if (!string.IsNullOrWhiteSpace(deviceId)) payload["device_id"] = deviceId;
            if (!string.IsNullOrWhiteSpace(macAddress)) payload["mac_address"] = macAddress;
            using var content = JsonBody(payload);

            HttpResponseMessage response;
            try
            {
                response = await SendAuthorizedAsync(session, HttpMethod.Post, "club-computers/register", content, cancellationToken).ConfigureAwait(false);
            }
            catch (ZafiroApiException ex) when (ex.Failure == ApiFailure.Forbidden)
            {
                return ApiResult<ComputerInfo>.Fail(ApiFailure.Forbidden, RegisterForbidden);
            }
            catch (ZafiroApiException ex)
            {
                return ApiResult<ComputerInfo>.Fail(ex.Failure, ex.Message);
            }

            using (response)
            {
                if (response.StatusCode == HttpStatusCode.NotFound)
                    return ApiResult<ComputerInfo>.Fail(ApiFailure.EndpointNotFound, EndpointMissing);

                if (IsTemporaryFailure(response.StatusCode))
                    return ApiResult<ComputerInfo>.Fail(ApiFailure.Network, L.T("Сервер Zafiro временно недоступен."));

                if (response.StatusCode is HttpStatusCode.BadRequest or HttpStatusCode.Conflict or HttpStatusCode.UnprocessableEntity)
                    return ApiResult<ComputerInfo>.Fail(ApiFailure.InvalidRequest, L.T("Сервер отклонил регистрацию ПК. Обратитесь к администратору клуба."));

                if (!response.IsSuccessStatusCode)
                    return ApiResult<ComputerInfo>.Fail(ApiFailure.Unknown, L.T("Сервер Zafiro вернул ошибку (:code).", ("code", (int)response.StatusCode)));

                return await ParseComputerAsync(response, cancellationToken).ConfigureAwait(false);
            }
        }

        private static async Task<ApiResult<ComputerInfo>> ParseComputerAsync(HttpResponseMessage response, CancellationToken cancellationToken)
        {
            var root = await ReadObjectAsync(response, cancellationToken).ConfigureAwait(false);
            if (root is not { } body || !TryGetLong(body, "computer_id", out var computerId) || computerId <= 0)
                return InvalidResponse<ComputerInfo>();

            var number = TryGetText(body, "number", out var n) ? n : computerId.ToString(CultureInfo.InvariantCulture);
            var name = TryGetString(body, "name", out var nm) ? nm : string.Empty;
            var agentToken = TryGetString(body, "agent_token", out var at) && at.All(c => char.IsAsciiLetterOrDigit(c) || c == '_') ? at : null;
            return ApiResult<ComputerInfo>.Ok(new ComputerInfo(computerId, number, name, ReadZoneName(body), agentToken));
        }

        private static async Task<string?> ReadErrorCodeAsync(HttpResponseMessage response, CancellationToken cancellationToken) =>
            await ReadObjectAsync(response, cancellationToken).ConfigureAwait(false) is { } body && TryGetString(body, "code", out var code) ? code : null;

        /// <summary>
        /// Отправляет статус ПК: с токеном устройства — на api/agent/heartbeat (только этот ПК),
        /// иначе — с токеном пользователя. 401/402/403 приводят к ZafiroApiException без повторов.
        /// </summary>
        public async Task<HeartbeatResponse> SendHeartbeatAsync(ClubSession session, long computerId, HeartbeatRequest request, CancellationToken cancellationToken, string? deviceToken = null)
        {
            var hardware = request.Hardware;
            var payload = new Dictionary<string, object?>
            {
                ["state"] = request.State.ToWire(),
                ["session_id"] = request.State == ComputerState.InSession && !string.IsNullOrEmpty(request.SessionId) ? request.SessionId : null,
                ["agent_version"] = request.AgentVersion,
                ["device_name"] = request.DeviceName,
                ["mac_address"] = hardware.MacAddress,
                ["local_ip"] = hardware.LocalIp,
                ["cpu_model"] = hardware.CpuModel,
                ["cpu_cores"] = hardware.CpuCores,
                ["ram_total_mb"] = hardware.RamTotalMb,
                ["gpu_model"] = hardware.GpuModel,
                ["os_version"] = hardware.OsVersion
            };

            if (request.SessionEndsAtUtc is { } sessionEndsAtUtc)
                payload["session_ends_at"] = sessionEndsAtUtc.ToUniversalTime().ToString("O", CultureInfo.InvariantCulture);

            using var content = JsonBody(payload);
            var path = deviceToken is null
                ? $"club-computers/{computerId.ToString(CultureInfo.InvariantCulture)}/heartbeat"
                : "agent/heartbeat";
            using var response = await SendAuthorizedAsync(session, HttpMethod.Post, path, content, cancellationToken, deviceToken).ConfigureAwait(false);

            if (response.StatusCode == HttpStatusCode.NotFound)
            {
                var code = await ReadErrorCodeAsync(response, cancellationToken).ConfigureAwait(false);
                throw code == ComputerNotFoundCode
                    ? new ZafiroApiException(ApiFailure.ComputerNotFound, ComputerNotRegistered)
                    : new ZafiroApiException(ApiFailure.EndpointNotFound, EndpointMissing);
            }

            if (response.StatusCode == HttpStatusCode.UnprocessableEntity)
                throw new ZafiroApiException(ApiFailure.InvalidRequest, L.T("Сервер отклонил статус ПК. Обратитесь к администратору."));

            // Повторяемые ошибки приходят как Network: цикл heartbeat их не останавливает.
            if (IsTemporaryFailure(response.StatusCode))
                throw new ZafiroApiException(ApiFailure.Network, L.T("Сервер Zafiro временно недоступен."));

            if (!response.IsSuccessStatusCode)
                throw new ZafiroApiException(ApiFailure.Unknown, L.T("Сервер Zafiro вернул ошибку (:code).", ("code", (int)response.StatusCode)));

            if (await ReadObjectAsync(response, cancellationToken).ConfigureAwait(false) is not { } root)
                throw new ZafiroApiException(ApiFailure.InvalidResponse, L.T("Сервер Zafiro вернул неожиданный ответ."));

            return ParseHeartbeat(root);
        }

        private static bool IsTemporaryFailure(HttpStatusCode status) =>
            status == HttpStatusCode.TooManyRequests || (int)status >= 500;

        private static StringContent JsonBody(object payload) =>
            new(JsonSerializer.Serialize(payload, JsonOptions), Encoding.UTF8, "application/json");

        private static async Task<JsonElement?> ReadObjectAsync(HttpResponseMessage response, CancellationToken cancellationToken)
        {
            try
            {
                var text = await response.Content.ReadAsStringAsync(cancellationToken).ConfigureAwait(false);
                using var document = JsonDocument.Parse(text);
                return document.RootElement.ValueKind == JsonValueKind.Object ? document.RootElement.Clone() : null;
            }
            catch (JsonException)
            {
                return null;
            }
        }

        private static HeartbeatResponse ParseHeartbeat(JsonElement root)
        {
            var heartbeat = new HeartbeatResponse();
            if (TryGetString(root, "status", out var status)) heartbeat.Status = status;
            if (TryGetString(root, "connection_status", out var connection)) heartbeat.ConnectionStatus = connection;

            if (TryGetString(root, "last_seen_at", out var seen) &&
                DateTimeOffset.TryParse(seen, CultureInfo.InvariantCulture, DateTimeStyles.AssumeUniversal, out var seenAt))
                heartbeat.LastSeenAt = seenAt.UtcDateTime;

            if (root.TryGetProperty("recommended_interval_seconds", out var interval) &&
                interval.ValueKind == JsonValueKind.Number && interval.TryGetInt32(out var seconds))
                heartbeat.RecommendedIntervalSeconds = seconds;

            if (TryGetText(root, "computer_id", out var id)) heartbeat.ComputerId = id;
            if (TryGetText(root, "number", out var number)) heartbeat.Number = number;
            heartbeat.Zone = ReadZoneName(root);

            if (root.TryGetProperty("session", out var session))
            {
                heartbeat.SessionReported = true;
                if (session.ValueKind == JsonValueKind.Object && TryGetString(session, "id", out var sessionId))
                {
                    heartbeat.SessionId = sessionId;
                    if (TryGetString(session, "ends_at", out var endsAt) &&
                        DateTimeOffset.TryParse(endsAt, CultureInfo.InvariantCulture, DateTimeStyles.AssumeUniversal, out var ends))
                        heartbeat.SessionEndsAtUtc = ends.ToUniversalTime();
                }
            }

            heartbeat.SessionExpired = root.TryGetProperty("session_expired", out var expired) && expired.ValueKind == JsonValueKind.True;
            if (TryGetString(root, "server_time", out var serverTime) &&
                DateTimeOffset.TryParse(serverTime, CultureInfo.InvariantCulture, DateTimeStyles.AssumeUniversal, out var serverNow))
                heartbeat.ServerTimeUtc = serverNow.ToUniversalTime();

            if (root.TryGetProperty("club", out var club) && club.ValueKind == JsonValueKind.Object)
            {
                heartbeat.ClubReported = true;
                if (TryGetString(club, "name", out var clubName)) heartbeat.ClubName = clubName;
                if (TryGetString(club, "logo_url", out var logoUrl)) heartbeat.ClubLogoUrl = logoUrl;
                if (TryGetString(club, "locale", out var clubLocale)) heartbeat.ClubLocale = clubLocale;
                if (club.TryGetProperty("after_session", out var afterSession) && afterSession.ValueKind == JsonValueKind.Object &&
                    TryGetString(afterSession, "action", out var afterAction))
                {
                    heartbeat.AfterSessionAction = afterAction;
                    if (afterSession.TryGetProperty("delay_seconds", out var delay) && delay.TryGetInt32(out var delaySeconds))
                        heartbeat.AfterSessionDelaySeconds = Math.Max(0, delaySeconds);
                }
                if (club.TryGetProperty("translations_version", out var version) && version.TryGetInt64(out var versionNumber)) heartbeat.TranslationsVersion = versionNumber;
                if (club.TryGetProperty("languages", out var languages) && languages.ValueKind == JsonValueKind.Array)
                {
                    heartbeat.ClubLanguages = languages.EnumerateArray()
                        .Where(item => item.ValueKind == JsonValueKind.Object && TryGetString(item, "code", out _) && TryGetString(item, "name", out _))
                        .Select(item => new LanguageInfo(item.GetProperty("code").GetString()!, item.GetProperty("name").GetString()!))
                        .ToList();
                }
            }

            return heartbeat;
        }

        private static string? ReadZoneName(JsonElement root) =>
            root.TryGetProperty("zone", out var zone) && zone.ValueKind == JsonValueKind.Object && TryGetString(zone, "name", out var name)
                ? name
                : null;

        // Строка или число (например, номер ПК 7 или "7").
        private static bool TryGetText(JsonElement root, string name, out string value)
        {
            value = string.Empty;
            if (!root.TryGetProperty(name, out var property)) return false;
            value = property.ValueKind switch
            {
                JsonValueKind.String => property.GetString() ?? string.Empty,
                JsonValueKind.Number => property.GetRawText(),
                _ => string.Empty
            };
            return value.Length > 0;
        }

        public static Uri WithTrailingSlash(Uri uri) =>
            uri.AbsolutePath.EndsWith('/') ? uri : new UriBuilder(uri) { Path = uri.AbsolutePath + "/" }.Uri;

        private static bool TryGetString(JsonElement root, string name, out string value)
        {
            value = string.Empty;
            if (!root.TryGetProperty(name, out var property) || property.ValueKind != JsonValueKind.String) return false;
            value = property.GetString() ?? string.Empty;
            return value.Length > 0;
        }

        private static bool TryGetLong(JsonElement root, string name, out long value)
        {
            value = 0;
            if (!root.TryGetProperty(name, out var property)) return false;
            return property.ValueKind switch
            {
                JsonValueKind.Number => property.TryGetInt64(out value),
                JsonValueKind.String => long.TryParse(property.GetString(), NumberStyles.None, CultureInfo.InvariantCulture, out value),
                _ => false
            };
        }
    }
}
