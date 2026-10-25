using System;
using System.IO;
using System.IO.Pipes;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;
using System.Threading;
using System.Threading.Tasks;

namespace ZafiroAgent.Service
{
    /// <summary>
    /// Сообщение между агентом и службой (одна строка JSON в каждую сторону).
    /// Запрос: Op = status | store | club-key | relay | resume | alive. Ответ: Ok и поля результата.
    /// </summary>
    public sealed class VaultMessage
    {
        public string? Op { get; set; }
        public bool Ok { get; set; }
        public string? Error { get; set; }

        public string? Token { get; set; }
        public bool HasToken { get; set; }
        public string? ClubKey { get; set; }
        public string? Locale { get; set; }

        public string? Method { get; set; }
        public string? Url { get; set; }
        public string? Authorization { get; set; }
        public string? ContentType { get; set; }
        public string? Body { get; set; }
        public int Status { get; set; }
    }

    /// <summary>Общие правила службы и агента: имя службы и канала, разрешённые запросы, формат токена.</summary>
    public static class VaultProtocol
    {
        public const string ServiceName = "ZafiroAgent";

        /// <summary>Настройки службы в HKLM (пишет установщик).</summary>
        public const string SettingsKey = @"SOFTWARE\Zafiro\Agent";
        public const string PipeName = "ZafiroAgent.Vault";
        public const string NoToken = "no_token";
        public const int MaxMessageBytes = 256 * 1024;

        public static readonly Uri HeartbeatEndpoint = new("https://zafiro.kz/api/agent/heartbeat");
        public static readonly Uri EnrollEndpoint = new("https://zafiro.kz/api/agent/enroll");

        /// <summary>Заголовок с UUID ПК: сервер не принимает токен ПК, присланный с другого «железа».</summary>
        public const string DeviceHeader = "X-Agent-Device";

        // Служба подставляет токен ПК только в эти запросы и только к zafiro.kz.
        private static readonly Regex RelayPath = new(@"^/api/(agent/heartbeat|agent/exit-pin|agent/user/login|agent/register|agent/qr-login|agent/qr-login/poll|guest/seat-transfer|guest/minutes/purchase|guest/shop/purchase|guest/chat|guest/packages/[0-9]{1,18}/start|tariffs/[0-9]{1,18}/purchase)$", RegexOptions.CultureInvariant);
        private static readonly Regex TokenFormat = new("^zag_[A-Za-z0-9]{48}$", RegexOptions.CultureInvariant);
        private static readonly Regex ClubKeyFormat = new("^[A-Za-z0-9]{64}$", RegexOptions.CultureInvariant);

        public static bool IsDeviceToken(string? token) => token is not null && TokenFormat.IsMatch(token);

        public static bool IsClubKey(string? key) => key is not null && ClubKeyFormat.IsMatch(key);

        public static bool IsAllowedRelayTarget(string? url, out Uri target)
        {
            target = null!;
            if (!Uri.TryCreate(url, UriKind.Absolute, out var uri)) return false;
            if (uri.Scheme != Uri.UriSchemeHttps || !uri.IsDefaultPort || !string.Equals(uri.Host, "zafiro.kz", StringComparison.OrdinalIgnoreCase)) return false;
            if (!string.IsNullOrEmpty(uri.UserInfo) || !string.IsNullOrEmpty(uri.Query) || !string.IsNullOrEmpty(uri.Fragment)) return false;
            if (!RelayPath.IsMatch(uri.AbsolutePath)) return false;
            target = uri;
            return true;
        }

        public static bool IsExitPin(Uri target) => target.AbsolutePath == "/api/agent/exit-pin";

        public static bool IsHeartbeat(Uri target) => target.AbsolutePath == "/api/agent/heartbeat";

        public static bool IsUserLogin(Uri target) => target.AbsolutePath == "/api/agent/user/login";

        public static async Task WriteAsync(Stream stream, VaultMessage message, CancellationToken cancellationToken)
        {
            var bytes = JsonSerializer.SerializeToUtf8Bytes(message);
            await stream.WriteAsync(bytes, cancellationToken).ConfigureAwait(false);
            stream.WriteByte((byte)'\n');
            await stream.FlushAsync(cancellationToken).ConfigureAwait(false);
        }

        /// <summary>Читает одну строку JSON не длиннее MaxMessageBytes; null — сообщение повреждено или слишком длинное.</summary>
        public static async Task<VaultMessage?> ReadAsync(Stream stream, CancellationToken cancellationToken)
        {
            using var buffer = new MemoryStream();
            var chunk = new byte[4096];
            while (true)
            {
                var read = await stream.ReadAsync(chunk, cancellationToken).ConfigureAwait(false);
                if (read == 0) break;
                var end = Array.IndexOf(chunk, (byte)'\n', 0, read);
                buffer.Write(chunk, 0, end < 0 ? read : end);
                if (buffer.Length > MaxMessageBytes) return null;
                if (end >= 0) break;
            }

            try
            {
                return buffer.Length == 0 ? null : JsonSerializer.Deserialize<VaultMessage>(Encoding.UTF8.GetString(buffer.ToArray()));
            }
            catch (JsonException)
            {
                return null;
            }
        }

        /// <summary>Сеанс Windows процесса на другом конце канала (для паузы перезапуска после выхода по PIN).</summary>
        public static uint ClientSessionId(NamedPipeServerStream pipe) =>
            GetNamedPipeClientSessionId(pipe.SafePipeHandle.DangerousGetHandle(), out var id) ? id : uint.MaxValue;

        public static uint ClientProcessId(NamedPipeServerStream pipe) =>
            GetNamedPipeClientProcessId(pipe.SafePipeHandle.DangerousGetHandle(), out var id) ? id : 0;

        /// <summary>Канал создан процессом в сеансе 0 (службой), а не программой пользователя, занявшей имя канала.</summary>
        /// <remarks>
        /// Сеанс берётся у самого канала: открыть процесс службы (SYSTEM) ограниченная учётная запись
        /// клиента не может, поэтому проверка через его PID всегда отказывала.
        /// </remarks>
        public static bool IsServedFromSession0(NamedPipeClientStream pipe) =>
            GetNamedPipeServerSessionId(pipe.SafePipeHandle.DangerousGetHandle(), out var session) && session == 0;

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern bool GetNamedPipeClientSessionId(IntPtr pipe, out uint sessionId);

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern bool GetNamedPipeClientProcessId(IntPtr pipe, out uint processId);

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern bool GetNamedPipeServerSessionId(IntPtr pipe, out uint sessionId);
    }
}
