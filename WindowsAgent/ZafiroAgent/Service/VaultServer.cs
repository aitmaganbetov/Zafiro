using System;
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
    /// Новый токен принимается, только если сервер его признаёт, а прежний уже отозван.
    /// </summary>
    public sealed class VaultServer
    {
        private const int MaxBodyChars = 64 * 1024;

        private readonly string _pipeName;
        private readonly DeviceVaultStore _store;
        private readonly HttpClient _http;
        private readonly Action<uint> _staffExit;
        private readonly Action<uint> _resume;
        private readonly Action<uint, uint> _alive;
        private readonly SemaphoreSlim _storeGate = new(1, 1);

        public VaultServer(string pipeName, DeviceVaultStore store, HttpClient http, Action<uint>? staffExit = null, Action<uint>? resume = null, Action<uint, uint>? alive = null)
        {
            _alive = alive ?? ((_, _) => { });
            _pipeName = pipeName;
            _store = store;
            _http = http;
            _staffExit = staffExit ?? (_ => { });
            _resume = resume ?? (_ => { });
        }

        public async Task RunAsync(CancellationToken cancellationToken)
        {
            while (!cancellationToken.IsCancellationRequested)
            {
                var pipe = CreatePipe();
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
            using (pipe)
            {
                try
                {
                    using var timeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
                    timeout.CancelAfter(TimeSpan.FromSeconds(60));
                    var request = await VaultProtocol.ReadAsync(pipe, timeout.Token).ConfigureAwait(false);
                    var session = VaultProtocol.ClientSessionId(pipe);
                    var reply = request is null ? Fail("bad_request") : await HandleAsync(request, session, timeout.Token, VaultProtocol.ClientProcessId(pipe)).ConfigureAwait(false);
                    await VaultProtocol.WriteAsync(pipe, reply, timeout.Token).ConfigureAwait(false);
                }
                catch (Exception ex)
                {
                    SafeLog.Write("[Service] request failed: " + ex.GetType().Name);
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
                case "relay":
                    return await RelayAsync(request, clientSession, cancellationToken).ConfigureAwait(false);
                default:
                    return Fail("bad_request");
            }
        }

        private async Task<VaultMessage> StoreAsync(string? token, CancellationToken cancellationToken)
        {
            if (!VaultProtocol.IsDeviceToken(token)) return Fail("bad_token");
            SafeLog.Register(token);

            await _storeGate.WaitAsync(cancellationToken).ConfigureAwait(false);
            try
            {
                var current = _store.Load();
                if (current == token) return new VaultMessage { Ok = true };

                // Сотрудник заново подключил ПК: сервер выдал новый токен и тем самым отозвал прежний.
                if (await ProbeAsync(token!, cancellationToken).ConfigureAwait(false) != HttpStatusCode.OK)
                    return Fail("token_rejected");
                if (current is not null && await ProbeAsync(current, cancellationToken).ConfigureAwait(false) != HttpStatusCode.Unauthorized)
                    return Fail("token_in_use");

                _store.Save(token!);
                SafeLog.Write("[Service] device token stored");
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

        // Пустой heartbeat: сервер только отмечает ПК в сети и отвечает, действителен ли токен.
        private async Task<HttpStatusCode> ProbeAsync(string token, CancellationToken cancellationToken)
        {
            using var request = new HttpRequestMessage(HttpMethod.Post, VaultProtocol.HeartbeatEndpoint)
            {
                Content = new StringContent("{}", Encoding.UTF8, "application/json")
            };
            request.Headers.Add("X-Agent-Token", token);
            request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
            using var response = await _http.SendAsync(request, cancellationToken).ConfigureAwait(false);
            return response.StatusCode;
        }

        private async Task<VaultMessage> RelayAsync(VaultMessage request, uint clientSession, CancellationToken cancellationToken)
        {
            if (request.Method != "POST" || !VaultProtocol.IsAllowedRelayTarget(request.Url, out var target)) return Fail("not_allowed");
            if (request.Body is { Length: > MaxBodyChars }) return Fail("too_large");

            var token = _store.Load();
            if (token is null) return Fail(VaultProtocol.NoToken);
            SafeLog.Register(token);

            using var message = new HttpRequestMessage(HttpMethod.Post, target)
            {
                Content = new StringContent(request.Body ?? string.Empty, Encoding.UTF8, "application/json")
            };
            message.Headers.Add("X-Agent-Token", token);
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
                    _staffExit(clientSession);
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

        private static VaultMessage Fail(string error) => new() { Ok = false, Error = error };
    }
}
