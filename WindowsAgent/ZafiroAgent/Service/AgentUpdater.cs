using System;
using System.ComponentModel;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Net.Http;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.RegularExpressions;
using System.Threading;
using System.Threading.Tasks;
using ZafiroAgent.Services;

namespace ZafiroAgent.Service
{
    /// <summary>Новая версия агента из ответа heartbeat службы: установщик сборки на GitHub и его SHA-256.</summary>
    public sealed record AgentUpdateOffer(Version Version, Uri Url, string Sha256);

    /// <summary>
    /// Автообновление: сервер предлагает новую версию, только когда ПК свободен (никто не вошёл,
    /// нет оплаченной сессии). Служба скачивает установщик с GitHub в свою папку (доступ только у
    /// SYSTEM и администраторов), сверяет SHA-256 с присланным сервером и запускает тихую установку.
    /// Установщик сам останавливает службу и агент и запускает их снова.
    /// </summary>
    public sealed class AgentUpdater
    {
        private const string DownloadHost = "github.com";
        // GitHub отдаёт файлы релизов перенаправлением на свои хранилища.
        private static readonly string[] AllowedHosts = { DownloadHost, "objects.githubusercontent.com", "release-assets.githubusercontent.com" };
        private const long MaxBytes = 300L * 1024 * 1024;
        private static readonly TimeSpan RetryAfter = TimeSpan.FromHours(1);
        private static readonly Regex Sha256Pattern = new("^[0-9a-f]{64}$", RegexOptions.CultureInvariant);

        private readonly string _directory;
        private readonly HttpClient _http;
        private readonly Version _current;
        private readonly Func<string, string, bool> _launch;
        private readonly Func<DateTime> _utcNow;
        private int _busy;
        private Version? _triedVersion;
        private DateTime _retryAfterUtc;

        /// <param name="launch">Запуск установщика (путь, аргументы); в тестах — заглушка.</param>
        public AgentUpdater(string directory, HttpClient http, string currentVersion, Func<string, string, bool>? launch = null, Func<DateTime>? utcNow = null)
        {
            _directory = directory;
            _http = http;
            _current = Version.TryParse(currentVersion, out var current) ? current : new Version(0, 0, 0);
            _launch = launch ?? StartInstaller;
            _utcNow = utcNow ?? (() => DateTime.UtcNow);
        }

        public static HttpClient CreateDownloadClient() =>
            new(new SocketsHttpHandler { AllowAutoRedirect = true, MaxAutomaticRedirections = 5 }) { Timeout = TimeSpan.FromMinutes(20) };

        /// <summary>Предложение из ответа heartbeat; null — его нет или оно неполное.</summary>
        public static AgentUpdateOffer? ParseOffer(string json)
        {
            try
            {
                using var document = JsonDocument.Parse(json);
                if (document.RootElement.ValueKind != JsonValueKind.Object
                    || !document.RootElement.TryGetProperty("agent_update", out var update)
                    || update.ValueKind != JsonValueKind.Object) return null;

                var version = Text(update, "version");
                var url = Text(update, "url");
                var sha256 = Text(update, "sha256");

                if (version is null || !Version.TryParse(version, out var parsed) || parsed.Build < 0 || parsed.Revision >= 0) return null;
                if (!Uri.TryCreate(url, UriKind.Absolute, out var uri) || !IsDownloadUrl(uri)) return null;
                if (sha256 is null || !Sha256Pattern.IsMatch(sha256)) return null;

                return new AgentUpdateOffer(parsed, uri, sha256);
            }
            catch (JsonException)
            {
                return null;
            }
        }

        private static string? Text(JsonElement element, string name) =>
            element.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.String ? value.GetString() : null;

        private static bool IsDownloadUrl(Uri uri) =>
            uri.Scheme == Uri.UriSchemeHttps && uri.IsDefaultPort && string.IsNullOrEmpty(uri.UserInfo)
            && string.Equals(uri.Host, DownloadHost, StringComparison.OrdinalIgnoreCase);

        /// <summary>
        /// Скачивает, проверяет и запускает установщик. Одна попытка на версию в час: сервер тоже
        /// ограничивает предложения (бездисковый ПК забывает обновление после перезагрузки).
        /// </summary>
        public async Task<bool> TryInstallAsync(AgentUpdateOffer offer, CancellationToken cancellationToken)
        {
            if (offer.Version <= _current) return false;
            if (offer.Version == _triedVersion && _utcNow() < _retryAfterUtc) return false;
            if (Interlocked.Exchange(ref _busy, 1) == 1) return false;

            _triedVersion = offer.Version;
            _retryAfterUtc = _utcNow() + RetryAfter;
            try
            {
                var path = await DownloadAsync(offer, cancellationToken).ConfigureAwait(false);
                if (path is null) return false;

                // Хэш считается по файлу, который и будет запущен: пока он открыт, записать в него нельзя.
                using var installer = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.Read);
                var hash = Convert.ToHexString(await SHA256.HashDataAsync(installer, cancellationToken).ConfigureAwait(false)).ToLowerInvariant();
                if (hash != offer.Sha256)
                {
                    SafeLog.Write($"[Service] agent {offer.Version} installer checksum mismatch: not installed");
                    installer.Dispose();
                    TryDelete(path);
                    return false;
                }

                SafeLog.Write($"[Service] installing agent {offer.Version}");
                var started = _launch(path, Arguments(Path.Combine(_directory, "install.log")));
                if (!started) SafeLog.Write("[Service] agent installer did not start");
                return started;
            }
            catch (Exception ex) when (ex is HttpRequestException or IOException or UnauthorizedAccessException or Win32Exception or InvalidOperationException
                                       || (ex is OperationCanceledException && !cancellationToken.IsCancellationRequested))
            {
                SafeLog.Write($"[Service] agent {offer.Version} update failed: " + ex.GetType().Name);
                return false;
            }
            catch (OperationCanceledException)
            {
                return false;
            }
            finally
            {
                Volatile.Write(ref _busy, 0);
            }
        }

        /// <summary>
        /// Тихая установка. Задача «защитить учётную запись клиента» не запускается снова: защита уже
        /// применена, а имя учётной записи клиента служба не знает.
        /// </summary>
        public static string Arguments(string logPath) =>
            $"/VERYSILENT /SUPPRESSMSGBOXES /NORESTART /SP- /MERGETASKS=\"!protect\" /LOG=\"{logPath}\"";

        private async Task<string?> DownloadAsync(AgentUpdateOffer offer, CancellationToken cancellationToken)
        {
            Directory.CreateDirectory(_directory);
            RemoveLeftovers();
            var path = Path.Combine(_directory, $"ZafiroAgentSetup-{offer.Version}.exe");

            using var response = await _http.GetAsync(offer.Url, HttpCompletionOption.ResponseHeadersRead, cancellationToken).ConfigureAwait(false);
            var final = response.RequestMessage?.RequestUri ?? offer.Url;
            if (!response.IsSuccessStatusCode
                || final.Scheme != Uri.UriSchemeHttps
                || !AllowedHosts.Contains(final.Host, StringComparer.OrdinalIgnoreCase)
                || response.Content.Headers.ContentLength > MaxBytes)
            {
                SafeLog.Write($"[Service] agent {offer.Version} download refused: HTTP {(int)response.StatusCode} from {final.Host}");
                return null;
            }

            await using (var source = await response.Content.ReadAsStreamAsync(cancellationToken).ConfigureAwait(false))
            await using (var target = new FileStream(path, FileMode.Create, FileAccess.Write, FileShare.None))
            {
                var buffer = new byte[81920];
                long total = 0;
                int read;
                while ((read = await source.ReadAsync(buffer, cancellationToken).ConfigureAwait(false)) > 0)
                {
                    total += read;
                    if (total > MaxBytes) throw new IOException("The installer is too large.");
                    await target.WriteAsync(buffer.AsMemory(0, read), cancellationToken).ConfigureAwait(false);
                }
            }

            return path;
        }

        /// <summary>Установщики прошлых обновлений (по 50+ МБ) удаляются; журнал установки остаётся.</summary>
        public void RemoveLeftovers()
        {
            try
            {
                if (!Directory.Exists(_directory)) return;
                foreach (var file in Directory.EnumerateFiles(_directory, "ZafiroAgentSetup-*.exe")) TryDelete(file);
            }
            catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
            {
            }
        }

        private static void TryDelete(string path)
        {
            try
            {
                File.Delete(path);
            }
            catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
            {
            }
        }

        private static bool StartInstaller(string path, string arguments)
        {
            using var process = Process.Start(new ProcessStartInfo(path, arguments)
            {
                UseShellExecute = false,
                CreateNoWindow = true,
                WorkingDirectory = Path.GetDirectoryName(path)!
            });
            return process is not null;
        }
    }
}
