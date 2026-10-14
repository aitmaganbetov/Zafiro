using System;
using System.IO.Pipes;
using System.Linq;
using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using Microsoft.Win32;
using ZafiroAgent.Service;

namespace ZafiroAgent.Services
{
    /// <summary>Агент ↔ служба «Zafiro Agent»: токен ПК хранится в службе, агент знает только метку.</summary>
    public sealed class DeviceVaultClient
    {
        private static readonly TimeSpan ConnectTimeout = TimeSpan.FromSeconds(2);

        private readonly string _pipeName;
        private readonly bool _requireService;

        public DeviceVaultClient(string pipeName, bool requireService = true)
        {
            _pipeName = pipeName;
            _requireService = requireService;
        }

        /// <summary>Клиент службы, если она установлена на этом ПК; иначе null (токен хранится у агента, как раньше).</summary>
        public static DeviceVaultClient? ForInstalledService() =>
            IsServiceInstalled() ? new DeviceVaultClient(VaultProtocol.PipeName) : null;

        public static bool IsServiceInstalled()
        {
            try
            {
                using var key = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Services\" + VaultProtocol.ServiceName);
                return key is not null;
            }
            catch (Exception)
            {
                return false;
            }
        }

        public bool HasToken() => Call(new VaultMessage { Op = "status" })?.HasToken == true;

        /// <summary>Сообщает службе, что агент запущен: наблюдение за этим сеансом снова включено.</summary>
        public void Resume() => Call(new VaultMessage { Op = "resume" });

        /// <summary>Сигнал «агент работает»: без него служба считает агент зависшим и перезапускает его.</summary>
        public void Alive() => Call(new VaultMessage { Op = "alive" });

        /// <summary>Передаёт токен службе. true — токен хранится в службе и агенту его держать не нужно.</summary>
        public async Task<bool> StoreAsync(string token, CancellationToken cancellationToken)
        {
            var reply = await CallAsync(new VaultMessage { Op = "store", Token = token }, TimeSpan.FromSeconds(45), cancellationToken).ConfigureAwait(false);
            if (reply?.Ok != true) SafeLog.Write("[Vault] device token was not stored: " + (reply?.Error ?? "service unavailable"));
            return reply?.Ok == true;
        }

        public Task<VaultMessage?> RelayAsync(VaultMessage request, CancellationToken cancellationToken) =>
            CallAsync(request, TimeSpan.FromSeconds(40), cancellationToken);

        private VaultMessage? Call(VaultMessage request) =>
            Task.Run(() => CallAsync(request, TimeSpan.FromSeconds(5), CancellationToken.None)).GetAwaiter().GetResult();

        private async Task<VaultMessage?> CallAsync(VaultMessage request, TimeSpan timeout, CancellationToken cancellationToken)
        {
            using var cts = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
            cts.CancelAfter(timeout);
            try
            {
                using var pipe = new NamedPipeClientStream(".", _pipeName, PipeDirection.InOut, PipeOptions.Asynchronous);
                await pipe.ConnectAsync((int)ConnectTimeout.TotalMilliseconds, cts.Token).ConfigureAwait(false);
                // Токен отдаётся только службе: канал с тем же именем, открытый программой клиента, не годится.
                if (_requireService && !VaultProtocol.IsServedFromSession0(pipe))
                {
                    SafeLog.Write("[Vault] pipe is not served by the Zafiro Agent service");
                    return null;
                }

                await VaultProtocol.WriteAsync(pipe, request, cts.Token).ConfigureAwait(false);
                return await VaultProtocol.ReadAsync(pipe, cts.Token).ConfigureAwait(false);
            }
            catch (Exception ex) when (ex is TimeoutException or System.IO.IOException or OperationCanceledException or UnauthorizedAccessException)
            {
                if (cancellationToken.IsCancellationRequested) throw;
                return null;
            }
        }
    }

    /// <summary>
    /// Где хранится токен ПК. Если служба установлена, агент хранит вместо токена метку
    /// <see cref="VaultMarker"/>, а запросы с ней отправляет <see cref="DeviceVaultHandler"/> через службу.
    /// </summary>
    public static class DeviceTokens
    {
        public const string VaultMarker = "vault";

        public static DeviceVaultClient? Vault { get; set; } = DeviceVaultClient.ForInstalledService();

        /// <summary>Что сохранить у агента после получения токена: метку, если токен принят службой, иначе сам токен.</summary>
        public static async Task<string> KeepAsync(string token, CancellationToken cancellationToken)
        {
            var vault = Vault;
            return vault is not null && await vault.StoreAsync(token, cancellationToken).ConfigureAwait(false) ? VaultMarker : token;
        }

        /// <summary>Токен для запроса: сохранённый у агента, а если его стёрли — метка службы.</summary>
        public static string? Resolve(string? stored) =>
            !string.IsNullOrEmpty(stored) ? stored : Vault?.HasToken() == true ? VaultMarker : null;
    }

    /// <summary>Запросы с меткой вместо токена ПК уходят через службу: она подставит настоящий токен.</summary>
    public sealed class DeviceVaultHandler : DelegatingHandler
    {
        private readonly DeviceVaultClient? _vault;

        public DeviceVaultHandler(HttpMessageHandler inner, DeviceVaultClient? vault = null)
            : base(inner)
        {
            _vault = vault;
        }

        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            if (!request.Headers.TryGetValues("X-Agent-Token", out var values) || values.FirstOrDefault() != DeviceTokens.VaultMarker)
                return await base.SendAsync(request, cancellationToken).ConfigureAwait(false);

            // Без службы метка ничего не значит: для сервера это недействительный токен.
            var vault = _vault ?? DeviceTokens.Vault;
            if (vault is null) return new HttpResponseMessage(HttpStatusCode.Unauthorized) { RequestMessage = request };

            var body = request.Content is null ? null : await request.Content.ReadAsStringAsync(cancellationToken).ConfigureAwait(false);
            var reply = await vault.RelayAsync(new VaultMessage
            {
                Op = "relay",
                Method = request.Method.Method,
                Url = request.RequestUri?.AbsoluteUri,
                Authorization = request.Headers.Authorization?.ToString(),
                Body = body
            }, cancellationToken).ConfigureAwait(false);

            if (reply is null || (!reply.Ok && reply.Error == "network"))
                throw new HttpRequestException("Служба Zafiro Agent недоступна или нет связи с сервером.");
            if (!reply.Ok)
            {
                SafeLog.Write("[Vault] request refused: " + reply.Error);
                return new HttpResponseMessage(HttpStatusCode.Unauthorized) { RequestMessage = request };
            }

            var content = new StringContent(reply.Body ?? string.Empty, Encoding.UTF8);
            content.Headers.ContentType = MediaTypeHeaderValue.TryParse(reply.ContentType, out var type) ? type : new MediaTypeHeaderValue("application/json");
            return new HttpResponseMessage((HttpStatusCode)reply.Status) { Content = content, RequestMessage = request };
        }
    }
}
