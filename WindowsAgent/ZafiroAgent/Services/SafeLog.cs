using System;
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
            if (sink is not null) sink(safe);
            else System.Diagnostics.Debug.WriteLine(safe);
        }
    }
}
