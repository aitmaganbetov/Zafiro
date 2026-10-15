using System;
using System.Collections.Generic;
using System.IO;
using System.IO.Pipes;
using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Security.Principal;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using ZafiroAgent.Services;

namespace ZafiroAgent.Service
{
    /// <summary>
    /// Канал службы для агента. Токен ПК никогда не покидает службу: агент присылает запрос
    /// (heartbeat, PIN, покупка), служба подставляет X-Agent-Token и отправляет его на zafiro.kz.
    /// Новый токен принимается, только если сервер его признаёт, а прежний уже отозван, и сразу
    /// меняется на сервере на другой: токен, прошедший через профиль клиента, перестаёт работать.
    /// </summary>
    public sealed class VaultServer
    {
        private const int MaxBodyChars = 64 * 1024;
        private const int MaxConnectionsPerProcess = 4;
        private const int MaxCreateFailures = 30;
        private static readonly TimeSpan ReadTimeout = TimeSpan.FromSeconds(5);
        public static readonly Uri RotateEndpoint = new("https://zafiro.kz/api/agent/token/rotate");
        private static readonly TimeSpan EnrollRetry = TimeSpan.FromMinutes(10);
        private static readonly TimeSpan EnrollRetryAfterError = TimeSpan.FromMinutes(1);

        private readonly string _pipeName;
        private readonly DeviceVaultStore _store;
        private readonly HttpClient _http;
        private readonly Action<uint, TimeSpan> _staffExit;
        private readonly Action<uint> _resume;
        private readonly Action<uint, uint> _alive;
        private readonly Func<string?> _macAddress;
        private readonly SemaphoreSlim _storeGate = new(1, 1);
        private readonly Dictionary<uint, int> _connections = new();
        private string? _rotatedFrom;
        private DateTime _enrollAfterUtc;

        public VaultServer(string pipeName, DeviceVaultStore store, HttpClient http, Action<uint, TimeSpan>? staffExit = null, Action<uint>? resume = null, Action<uint, uint>? alive = null, Func<string?>? macAddress = null)
        {
            _macAddress = macAddress ?? (() => null);
            _alive = alive ?? ((_, _) => { });
            _pipeName = pipeName;
            _store = store;
            _http = http;
            _staffExit = staffExit ?? ((_, _) => { });
            _resume = resume ?? (_ => { });
        }

        /// <summary>
        /// Принимает подключения. Если канал не создаётся (например, имя заняла программа клиента,
        /// пока служба перезапускалась), повторяет попытки, а затем завершает процесс с ошибкой,
        /// чтобы Windows перезапустила службу.
        /// </summary>
        public async Task RunAsync(CancellationToken cancellationToken, Action? fatal = null)
        {
            var failures = 0;
            while (!cancellationToken.IsCancellationRequested)
            {
                NamedPipeServerStream pipe;
                try
                {
                    pipe = CreatePipe();
                    failures = 0;
                }
                catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
                {
                    SafeLog.Write("[Service] could not open the pipe: " + ex.Message);
                    if (++failures >= MaxCreateFailures)
                    {
                        (fatal ?? (() => Environment.Exit(1)))();
                        return;
                    }

                    try
                    {
                        await Task.Delay(TimeSpan.FromSeconds(1), cancellationToken).ConfigureAwait(false);
                    }
                    catch (OperationCanceledException)
                    {
                        return;
                    }

                    continue;
                }

                try
                {
                    await pipe.WaitForConnectionAsync(cancellationToken).ConfigureAwait(false);
                }
                catch
                {
                    pipe.Dispose();
                    if (cancellationToken.IsCancellationRequested) return;
                    continue;
                }

                _ = Task.Run(() => ServeAsync(pipe, cancellationToken), CancellationToken.None);
            }
        }

        // Одна программа не может занять канал: не больше нескольких подключений от процесса одновременно.
        private bool Enter(uint process)
        {
            lock (_connections)
            {
                _connections.TryGetValue(process, out var count);
                if (count >= MaxConnectionsPerProcess) return false;
                _connections[process] = count + 1;
                return true;
            }
        }

        private void Leave(uint process)
        {
            lock (_connections)
            {
                if (_connections.TryGetValue(process, out var count) && count > 1) _connections[process] = count - 1;
                else _connections.Remove(process);
            }
        }

        // Подключаться могут вошедшие пользователи, создавать экземпляры канала — только SYSTEM и администраторы.
        private NamedPipeServerStream CreatePipe()
        {
            var security = new PipeSecurity();
            security.AddAccessRule(new PipeAccessRule(new SecurityIdentifier(WellKnownSidType.LocalSystemSid, null), PipeAccessRights.FullControl, System.Security.AccessControl.AccessControlType.Allow));
            security.AddAccessRule(new PipeAccessRule(new SecurityIdentifier(WellKnownSidType.BuiltinAdministratorsSid, null), PipeAccessRights.FullControl, System.Security.AccessControl.AccessControlType.Allow));
            security.AddAccessRule(new PipeAccessRule(new SecurityIdentifier(WellKnownSidType.AuthenticatedUserSid, null), PipeAccessRights.ReadWrite, System.Security.AccessControl.AccessControlType.Allow));
            return NamedPipeServerStreamAcl.Create(_pipeName, PipeDirection.InOut, NamedPipeServerStream.MaxAllowedServerInstances,
                PipeTransmissionMode.Byte, PipeOptions.Asynchronous, 0, 0, security);
        }

        private async Task ServeAsync(NamedPipeServerStream pipe, CancellationToken cancellationToken)
        {
            var process = VaultProtocol.ClientProcessId(pipe);
            using (pipe)
            {
                if (!Enter(process)) return;
                try
                {
                    // Запрос нужно прислать сразу; ответ (запрос к серверу) может занять до 45 с.
                    using var read = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
                    read.CancelAfter(ReadTimeout);
                    var request = await VaultProtocol.ReadAsync(pipe, read.Token).ConfigureAwait(false);

                    using var work = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
                    work.CancelAfter(TimeSpan.FromSeconds(45));
                    var session = VaultProtocol.ClientSessionId(pipe);
                    var reply = request is null ? Fail("bad_request") : await HandleAsync(request, session, work.Token, process).ConfigureAwait(false);
                    await VaultProtocol.WriteAsync(pipe, reply, work.Token).ConfigureAwait(false);
                }
                catch (Exception ex)
                {
                    SafeLog.Write("[Service] request failed: " + ex.GetType().Name);
                }
                finally
                {
                    Leave(process);
                }
            }
        }

        public async Task<VaultMessage> HandleAsync(VaultMessage request, uint clientSession, CancellationToken cancellationToken, uint clientProcess = 0)
        {
            switch (request.Op)
            {
                case "alive":
                    _alive(clientSession, clientProcess);
                    return new VaultMessage { Ok = true };
                case "status":
                    return new VaultMessage { Ok = true, HasToken = _store.Load() is not null };
                case "resume":
                    _resume(clientSession);
                    return new VaultMessage { Ok = true };
                case "store":
                    return await StoreAsync(request.Token, cancellationToken).ConfigureAwait(false);
                case "club-key":
                    return SetClubKey(request.ClubKey);
                case "relay":
                    return await RelayAsync(request, clientSession, cancellationToken).ConfigureAwait(false);
                default:
                    return Fail("bad_request");
            }
        }

        /// <summary>
        /// Ключ установки клуба запоминается один раз: программа клиента не может подменить его
        /// ключом другого клуба. Подключить ПК к другому клубу — переустановить агент.
        /// </summary>
        private VaultMessage SetClubKey(string? key)
        {
            if (!VaultProtocol.IsClubKey(key)) return Fail("bad_key");
            SafeLog.Register(key);

            var current = _store.LoadClubKey();
            if (current is null)
            {
                _store.SaveClubKey(key!);
                _enrollAfterUtc = DateTime.MinValue;
                SafeLog.Write("[Service] club key stored");
                return new VaultMessage { Ok = true };
            }

            return current == key ? new VaultMessage { Ok = true } : Fail("club_key_locked");
        }

        private async Task<VaultMessage> StoreAsync(string? token, CancellationToken cancellationToken)
        {
            if (!VaultProtocol.IsDeviceToken(token)) return Fail("bad_token");
            SafeLog.Register(token);

            await _storeGate.WaitAsync(cancellationToken).ConfigureAwait(false);
            try
            {
                var current = _store.Load();
                if (current == token || (_rotatedFrom is not null && _rotatedFrom == token)) return new VaultMessage { Ok = true };

                // Сотрудник заново подключил ПК: сервер выдал новый токен и тем самым отозвал прежний.
                if (await ProbeAsync(token!, cancellationToken).ConfigureAwait(false) != HttpStatusCode.OK)
                    return Fail("token_rejected");
                if (current is not null && await ProbeAsync(current, cancellationToken).ConfigureAwait(false) != HttpStatusCode.Unauthorized)
                    return Fail("token_in_use");

                _store.Save(token!);
                SafeLog.Write("[Service] device token stored");
                await RotateAsync(token!, cancellationToken).ConfigureAwait(false);
                return new VaultMessage { Ok = true };
            }
            catch (HttpRequestException)
            {
                return Fail("network");
            }
            catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
            {
                return Fail("network");
            }
            finally
            {
                _storeGate.Release();
            }
        }

        /// <summary>
        /// Меняет токен на сервере: прежний (его видел агент в сеансе клиента) отзывается, новый
        /// есть только у службы. Старый сервер без этого запроса оставляет токен как есть.
        /// </summary>
        private async Task RotateAsync(string token, CancellationToken cancellationToken)
        {
            try
            {
                await TryRotateAsync(token, cancellationToken).ConfigureAwait(false);
            }
            catch (Exception ex) when (ex is HttpRequestException or System.Text.Json.JsonException or InvalidOperationException
                                       || (ex is OperationCanceledException && !cancellationToken.IsCancellationRequested))
            {
                SafeLog.Write("[Service] device token was not rotated: " + ex.GetType().Name);
            }
        }

        private async Task TryRotateAsync(string token, CancellationToken cancellationToken)
        {
            using var request = new HttpRequestMessage(HttpMethod.Post, RotateEndpoint) { Content = new StringContent("{}", Encoding.UTF8, "application/json") };
            Sign(request, token);
            request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
            using var response = await _http.SendAsync(request, cancellationToken).ConfigureAwait(false);
            if (!response.IsSuccessStatusCode)
            {
                SafeLog.Write("[Service] device token was not rotated: " + (int)response.StatusCode);
                return;
            }

            using var json = System.Text.Json.JsonDocument.Parse(await response.Content.ReadAsStringAsync(cancellationToken).ConfigureAwait(false));
            if (json.RootElement.TryGetProperty("agent_token", out var value) && value.GetString() is { } rotated && VaultProtocol.IsDeviceToken(rotated))
            {
                SafeLog.Register(rotated);
                _store.Save(rotated);
                _rotatedFrom = token;
                SafeLog.Write("[Service] device token rotated");
            }
        }

        /// <summary>
        /// Пустой heartbeat от службы: ПК отмечается в сети, даже когда в агенте никто не вошёл.
        /// Токен, отвергнутый сервером, удаляется; без токена служба получает его по ключу клуба.
        /// </summary>
        public async Task KeepOnlineAsync(TimeSpan interval, CancellationToken cancellationToken)
        {
            while (!cancellationToken.IsCancellationRequested)
            {
                await KeepOnlineOnceAsync(cancellationToken).ConfigureAwait(false);

                try
                {
                    await Task.Delay(interval, cancellationToken).ConfigureAwait(false);
                }
                catch (OperationCanceledException)
                {
                    return;
                }
            }
        }

        public async Task KeepOnlineOnceAsync(CancellationToken cancellationToken)
        {
            try
            {
                if (_store.Load() is { } token)
                {
                    if (await ProbeAsync(token, cancellationToken).ConfigureAwait(false) == HttpStatusCode.Unauthorized)
                        await ForgetAsync(token, cancellationToken).ConfigureAwait(false);
                }
                else
                {
                    await EnrollAsync(cancellationToken).ConfigureAwait(false);
                }
            }
            catch (Exception ex) when (ex is HttpRequestException or System.Text.Json.JsonException or IOException or UnauthorizedAccessException
                                       || (ex is OperationCanceledException && !cancellationToken.IsCancellationRequested))
            {
                SafeLog.Write("[Service] keep-online failed: " + ex.GetType().Name);
            }
        }

        // Токен отозван или скопирован с другого ПК (образ бездисковой системы). Удаляется, только
        // если за время проверки сотрудник не сохранил новый.
        private async Task ForgetAsync(string token, CancellationToken cancellationToken)
        {
            await _storeGate.WaitAsync(cancellationToken).ConfigureAwait(false);
            try
            {
                if (_store.Load() != token) return;
                _store.Clear();
                _enrollAfterUtc = DateTime.MinValue;
                SafeLog.Write("[Service] device token rejected by the server and removed");
            }
            finally
            {
                _storeGate.Release();
            }
        }

        /// <summary>
        /// Бездисковый ПК после перезагрузки (и ПК, загруженный с чужого образа) получает токен
        /// своего ПК по ключу клуба и UUID. Сервер выдаёт его, только если ПК уже зарегистрирован
        /// в клубе; иначе — повтор через 10 минут (сотрудник регистрирует ПК, войдя на нём в агенте).
        /// </summary>
        private async Task EnrollAsync(CancellationToken cancellationToken)
        {
            if (DateTime.UtcNow < _enrollAfterUtc) return;
            var key = _store.LoadClubKey();
            var deviceId = _store.DeviceId;
            if (key is null || string.IsNullOrEmpty(deviceId)) return;
            SafeLog.Register(key);

            _enrollAfterUtc = DateTime.UtcNow + EnrollRetry;
            // MAC — чтобы сервер не выдал токен другому ПК с таким же UUID.
            var body = System.Text.Json.JsonSerializer.Serialize(new { club_key = key, device_id = deviceId, device_name = Environment.MachineName, mac_address = _macAddress() });
            using var request = new HttpRequestMessage(HttpMethod.Post, VaultProtocol.EnrollEndpoint) { Content = new StringContent(body, Encoding.UTF8, "application/json") };
            request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
            using var response = await _http.SendAsync(request, cancellationToken).ConfigureAwait(false);
            if (!response.IsSuccessStatusCode)
            {
                SafeLog.Write("[Service] club key enrollment refused: " + (int)response.StatusCode);
                if (response.StatusCode == HttpStatusCode.TooManyRequests || (int)response.StatusCode >= 500)
                    _enrollAfterUtc = DateTime.UtcNow + EnrollRetryAfterError;
                return;
            }

            using var json = System.Text.Json.JsonDocument.Parse(await response.Content.ReadAsStringAsync(cancellationToken).ConfigureAwait(false));
            if (!json.RootElement.TryGetProperty("agent_token", out var value) || value.GetString() is not { } token || !VaultProtocol.IsDeviceToken(token))
                return;

            SafeLog.Register(token);
            await _storeGate.WaitAsync(cancellationToken).ConfigureAwait(false);
            try
            {
                if (_store.Load() is null) _store.Save(token);
            }
            finally
            {
                _storeGate.Release();
            }

            _enrollAfterUtc = DateTime.MinValue;
            SafeLog.Write("[Service] device token received with the club key");
        }

        // Токен ПК и UUID этого ПК: сервер не принимает токен с другого «железа».
        private void Sign(HttpRequestMessage request, string token)
        {
            request.Headers.Add("X-Agent-Token", token);
            if (_store.DeviceId is { Length: > 0 } deviceId) request.Headers.Add(VaultProtocol.DeviceHeader, deviceId);
        }

        // Пустой heartbeat: сервер только отмечает ПК в сети и отвечает, действителен ли токен.
        private async Task<HttpStatusCode> ProbeAsync(string token, CancellationToken cancellationToken)
        {
            using var request = new HttpRequestMessage(HttpMethod.Post, VaultProtocol.HeartbeatEndpoint)
            {
                Content = new StringContent("{}", Encoding.UTF8, "application/json")
            };
            Sign(request, token);
            request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
            using var response = await _http.SendAsync(request, cancellationToken).ConfigureAwait(false);
            return response.StatusCode;
        }

        private async Task<VaultMessage> RelayAsync(VaultMessage request, uint clientSession, CancellationToken cancellationToken)
        {
            if (request.Method != "POST" || !VaultProtocol.IsAllowedRelayTarget(request.Url, out var target)) return Fail("not_allowed");
            if (request.Body is { Length: > MaxBodyChars }) return Fail("too_large");

            HttpRequestMessage message;
            if (VaultProtocol.IsUserLogin(target))
            {
                // Вход клиента: ключ клуба подставляет служба, в профиле клиента его нет.
                // Токен ПК сюда не нужен и не отправляется.
                var key = _store.LoadClubKey();
                if (key is null) return Fail("no_club_key");
                SafeLog.Register(key);
                if (WithClubKey(request.Body, key) is not { } loginBody) return Fail("bad_request");
                message = new HttpRequestMessage(HttpMethod.Post, target) { Content = new StringContent(loginBody, Encoding.UTF8, "application/json") };
            }
            else
            {
                var token = _store.Load();
                if (token is null) return Fail(VaultProtocol.NoToken);
                SafeLog.Register(token);
                message = new HttpRequestMessage(HttpMethod.Post, target) { Content = new StringContent(request.Body ?? string.Empty, Encoding.UTF8, "application/json") };
                Sign(message, token);
            }

            using var disposeMessage = message;
            // Язык агента: сервер отвечает на языке, который видит клиент.
            if (request.Locale is { Length: >= 2 and <= 8 } locale && locale.All(c => c is >= 'a' and <= 'z'))
            {
                message.Headers.TryAddWithoutValidation("X-Agent-Locale", locale);
                message.Headers.TryAddWithoutValidation("Accept-Language", locale);
            }
            message.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
            if (request.Authorization is { } authorization)
            {
                if (!AuthenticationHeaderValue.TryParse(authorization, out var parsed) || parsed.Scheme != "Bearer" || string.IsNullOrEmpty(parsed.Parameter))
                    return Fail("not_allowed");
                message.Headers.Authorization = parsed;
            }

            try
            {
                using var response = await _http.SendAsync(message, cancellationToken).ConfigureAwait(false);
                var body = await response.Content.ReadAsStringAsync(cancellationToken).ConfigureAwait(false);
                if (body.Length > MaxBodyChars * 4) body = string.Empty;

                if (VaultProtocol.IsExitPin(target) && response.StatusCode == HttpStatusCode.OK)
                {
                    SafeLog.Write("[Service] staff exit confirmed, restart paused for session " + clientSession);
                    _staffExit(clientSession, StaffTime(body));
                }

                return new VaultMessage { Ok = true, Status = (int)response.StatusCode, Body = body, ContentType = response.Content.Headers.ContentType?.MediaType };
            }
            catch (HttpRequestException)
            {
                return Fail("network");
            }
            catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
            {
                return Fail("network");
            }
        }

        /// <summary>Время обслуживания из ответа сервера (настройка клуба); старый сервер его не присылает — 15 минут.</summary>
        public static TimeSpan StaffTime(string body)
        {
            try
            {
                using var json = System.Text.Json.JsonDocument.Parse(body);
                if (json.RootElement.ValueKind == System.Text.Json.JsonValueKind.Object
                    && json.RootElement.TryGetProperty("staff_minutes", out var value)
                    && value.TryGetInt32(out var minutes) && minutes is >= 1 and <= 240)
                    return TimeSpan.FromMinutes(minutes);
            }
            catch (System.Text.Json.JsonException)
            {
            }

            return Watchdog.DefaultStaffPause;
        }

        // Тело входа — объект JSON; club_key в нём заменяется настоящим ключом.
        private static string? WithClubKey(string? body, string key)
        {
            try
            {
                if (System.Text.Json.Nodes.JsonNode.Parse(body ?? string.Empty) is not System.Text.Json.Nodes.JsonObject json) return null;
                json["club_key"] = key;
                return json.ToJsonString();
            }
            catch (System.Text.Json.JsonException)
            {
                return null;
            }
        }

        private static VaultMessage Fail(string error) => new() { Ok = false, Error = error };
    }
}
