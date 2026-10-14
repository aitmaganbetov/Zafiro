using System;
using System.IO;
using System.Collections.Generic;
using System.Threading;
using System.Text.RegularExpressions;

namespace ZafiroAgent.Services
{
    /// <summary>Единая точка записи диагностики: ключ, пароль и токен маскируются до вывода.</summary>
    public static class SafeLog
    {
        private static readonly object Gate = new();
        private static readonly List<string> Secrets = new();
        private static readonly AsyncLocal<Action<string>?> TestSink = new();

        private static readonly Regex BearerPattern = new(@"Bearer\s+[A-Za-z0-9\-._~+/=]+", RegexOptions.IgnoreCase | RegexOptions.Compiled);

        private static readonly Regex JsonFieldPattern = new(
            @"(""(?:password|club_key|token|access_token|api_token|authorization)""\s*:\s*"")[^""]*("")",
            RegexOptions.IgnoreCase | RegexOptions.Compiled);

        // Другие формы: password=..., club_key: ..., неполный JSON.
        private static readonly Regex KeyValuePattern = new(
            @"(?<key>password|passwd|club_key|access_token|api_token|token|secret)(?<sep>[""']?\s*[:=]\s*[""']?)[^""'\s,;}&]+",
            RegexOptions.IgnoreCase | RegexOptions.Compiled);

        // Только для тестов: перехват записи в пределах текущего асинхронного контекста.
        public static void UseSink(Action<string>? sink) => TestSink.Value = sink;

        /// <summary>Запоминает значение секрета, чтобы маскировать его в любом тексте.</summary>
        public static void Register(string? secret)
        {
            if (string.IsNullOrEmpty(secret) || secret.Length < 4) return;
            lock (Gate)
            {
                if (!Secrets.Contains(secret)) Secrets.Add(secret);
            }
        }

        public static string Redact(string? text)
        {
            if (string.IsNullOrEmpty(text)) return string.Empty;

            var result = BearerPattern.Replace(text, "Bearer ***");
            result = JsonFieldPattern.Replace(result, "$1***$2");
            result = KeyValuePattern.Replace(result, "${key}${sep}***");

            string[] known;
            lock (Gate) known = Secrets.ToArray();
            foreach (var secret in known)
            {
                result = result.Replace(secret, "***", StringComparison.Ordinal);
            }

            return result;
        }

        public static void Write(string message)
        {
            var safe = Redact(message);
            var sink = TestSink.Value;
            if (sink is not null)
            {
                sink(safe);
                return;
            }

            System.Diagnostics.Debug.WriteLine(safe);
            WriteToFile(safe);
        }

        // %LOCALAPPDATA%\ZafiroAgent\logs\agent-ГГГГММДД.log, хранятся 7 дней. Ошибки записи не мешают работе.
        private static readonly string LogDirectory = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "ZafiroAgent", "logs");
        private static readonly object FileGate = new();
        private static DateTime _cleanedOn;
        private static bool _directoryReady;

        private static void WriteToFile(string safe)
        {
            try
            {
                lock (FileGate)
                {
                    if (!_directoryReady)
                    {
                        Directory.CreateDirectory(LogDirectory);
                        _directoryReady = true;
                    }

                    var now = DateTime.Now;
                    File.AppendAllText(Path.Combine(LogDirectory, $"agent-{now:yyyyMMdd}.log"), $"{now:HH:mm:ss.fff} {safe}{Environment.NewLine}");

                    if (_cleanedOn != now.Date)
                    {
                        _cleanedOn = now.Date;
                        foreach (var file in Directory.EnumerateFiles(LogDirectory, "agent-*.log"))
                        {
                            if (File.GetLastWriteTime(file) < now.AddDays(-7)) File.Delete(file);
                        }
                    }
                }
            }
            catch
            {
                // Диагностика не должна ломать агент.
            }
        }
    }
}
