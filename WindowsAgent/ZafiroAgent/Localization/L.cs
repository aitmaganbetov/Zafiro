using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Text.Json;
using System.Text.RegularExpressions;
using ZafiroAgent.Services;

namespace ZafiroAgent.Localization
{
    public sealed record LanguageInfo(string Code, string Name);

    /// <summary>
    /// Тексты агента. Ключ — русский текст, как в группе переводов «agent» на сервере.
    /// Встроенные переводы (Frontend/i18n/*.json) работают без сети и на бездисковых ПК;
    /// правки суперадмина приходят с сервера поверх них. Язык — выбранный клиентом, иначе
    /// язык клуба, иначе русский. Нет перевода — английский текст, затем русский.
    /// </summary>
    public static class L
    {
        public const string SourceLocale = "ru";
        private const string FallbackLocale = "en";
        private static readonly Regex CodeFormat = new("^[a-z]{2,8}$", RegexOptions.CultureInvariant);
        private static readonly object Gate = new();

        // Словари заменяются целиком: чтение из любых потоков без блокировок.
        private static volatile State _state = State.Empty;

        /// <summary>Сменился язык или пришли новые переводы (вызывается в потоке, где это произошло).</summary>
        public static event Action? Changed;

        public static string Current => _state.UserLocale ?? _state.ClubLocale ?? SourceLocale;

        public static IReadOnlyList<LanguageInfo> Languages
        {
            get
            {
                var state = _state;
                if (state.ServerLanguages.Count > 0) return state.ServerLanguages;
                return state.BuiltIn.Keys.OrderBy(Order).Select(code => new LanguageInfo(code, state.Names.GetValueOrDefault(code, code))).ToArray();
            }
        }

        public static string T(string key) => Lookup(_state, Current, key);

        /// <summary>Перевод с подстановкой «:name» (как в Laravel).</summary>
        public static string T(string key, params (string Name, object? Value)[] args)
        {
            var text = T(key);
            foreach (var (name, value) in args.OrderByDescending(a => a.Name.Length))
                text = text.Replace(":" + name, Convert.ToString(value, System.Globalization.CultureInfo.InvariantCulture) ?? string.Empty, StringComparison.Ordinal);
            return text;
        }

        /// <summary>Встроенные переводы из папки с файлами {code}.json.</summary>
        public static void LoadBuiltIn(string directory)
        {
            var lines = new Dictionary<string, IReadOnlyDictionary<string, string>>();
            var names = new Dictionary<string, string>();
            try
            {
                foreach (var file in Directory.Exists(directory) ? Directory.GetFiles(directory, "*.json") : Array.Empty<string>())
                {
                    if (Parse(File.ReadAllText(file)) is not { } pack) continue;
                    lines[pack.Locale] = pack.Lines;
                    names[pack.Locale] = pack.Name ?? pack.Locale;
                }
            }
            catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
            {
                SafeLog.Write("[I18n] built-in texts were not loaded: " + ex.Message);
            }

            Update(state => state with { BuiltIn = lines, Names = names });
        }

        /// <summary>Язык клуба (с сервера или из настроек агента).</summary>
        public static void SetClubLocale(string? code)
        {
            if (code is not null && !CodeFormat.IsMatch(code)) return;
            Update(state => state with { ClubLocale = code });
        }

        /// <summary>Язык, выбранный клиентом на этом ПК; null — снова язык клуба.</summary>
        public static bool SetUserLocale(string? code)
        {
            if (code is not null && (!CodeFormat.IsMatch(code) || Languages.All(l => l.Code != code))) return false;
            Update(state => state with { UserLocale = code });
            return true;
        }

        public static void SetServerLanguages(IReadOnlyList<LanguageInfo> languages)
        {
            var valid = languages.Where(l => CodeFormat.IsMatch(l.Code) && !string.IsNullOrWhiteSpace(l.Name)).ToArray();
            if (valid.Length == 0) return;
            if (_state.ServerLanguages.SequenceEqual(valid)) return;
            Update(state => state with { ServerLanguages = valid });
        }

        /// <summary>Версия переводов с сервера, которые уже загружены для языка (null — не загружались).</summary>
        public static long? ServerVersion(string locale) =>
            _state.ServerVersions.TryGetValue(locale, out var version) ? version : null;

        public static void SetServerLines(string locale, long version, IReadOnlyDictionary<string, string> lines)
        {
            if (!CodeFormat.IsMatch(locale)) return;
            Update(state => state with
            {
                Server = new Dictionary<string, IReadOnlyDictionary<string, string>>(state.Server) { [locale] = lines },
                ServerVersions = new Dictionary<string, long>(state.ServerVersions) { [locale] = version }
            });
        }

        /// <summary>
        /// Все тексты языка для интерфейса: перевод, а где его нет — английский. Русский ключ
        /// интерфейс показывает сам, если нет ни того ни другого.
        /// </summary>
        public static IReadOnlyDictionary<string, string> LinesFor(string locale)
        {
            var state = _state;
            var result = new Dictionary<string, string>(StringComparer.Ordinal);
            var chain = locale == SourceLocale ? new[] { SourceLocale } : new[] { FallbackLocale, locale };
            foreach (var code in chain)
            {
                foreach (var source in new[] { state.BuiltIn, state.Server })
                {
                    if (!source.TryGetValue(code, out var lines)) continue;
                    foreach (var (key, value) in lines)
                        if (!string.IsNullOrEmpty(value)) result[key] = value;
                }
            }

            return result;
        }

        /// <summary>Исходное состояние (для тестов).</summary>
        public static void Reset() => Update(_ => State.Empty);

        /// <summary>Разбирает {"locale":"kk","name":"Қазақша","lines":{...}} и ответ API {"locale","version","lines"}.</summary>
        public static TextPack? Parse(string json)
        {
            try
            {
                using var document = JsonDocument.Parse(json);
                var root = document.RootElement;
                if (root.ValueKind != JsonValueKind.Object
                    || !root.TryGetProperty("locale", out var locale) || locale.GetString() is not { } code || !CodeFormat.IsMatch(code)
                    || !root.TryGetProperty("lines", out var linesElement) || linesElement.ValueKind != JsonValueKind.Object)
                    return null;

                var lines = new Dictionary<string, string>(StringComparer.Ordinal);
                foreach (var property in linesElement.EnumerateObject())
                    if (property.Value.ValueKind == JsonValueKind.String) lines[property.Name] = property.Value.GetString()!;

                var name = root.TryGetProperty("name", out var nameElement) && nameElement.ValueKind == JsonValueKind.String ? nameElement.GetString() : null;
                long? version = root.TryGetProperty("version", out var versionElement) && versionElement.TryGetInt64(out var v) ? v : null;
                return new TextPack(code, name, version, lines);
            }
            catch (JsonException)
            {
                return null;
            }
        }

        private static string Lookup(State state, string locale, string key)
        {
            foreach (var code in locale == SourceLocale ? new[] { SourceLocale } : new[] { locale, FallbackLocale })
            {
                if (state.Server.TryGetValue(code, out var server) && server.TryGetValue(key, out var fromServer) && !string.IsNullOrEmpty(fromServer)) return fromServer;
                if (state.BuiltIn.TryGetValue(code, out var builtIn) && builtIn.TryGetValue(key, out var shipped) && !string.IsNullOrEmpty(shipped)) return shipped;
            }

            return key;
        }

        private static int Order(string code) => code switch { "ru" => 0, "kk" => 1, "en" => 2, _ => 3 };

        private static void Update(Func<State, State> change)
        {
            bool changed;
            lock (Gate)
            {
                var next = change(_state);
                changed = !ReferenceEquals(next, _state) && next != _state;
                _state = next;
            }

            if (changed) Changed?.Invoke();
        }

        public sealed record TextPack(string Locale, string? Name, long? Version, IReadOnlyDictionary<string, string> Lines);

        private sealed record State(
            IReadOnlyDictionary<string, IReadOnlyDictionary<string, string>> BuiltIn,
            IReadOnlyDictionary<string, IReadOnlyDictionary<string, string>> Server,
            IReadOnlyDictionary<string, long> ServerVersions,
            IReadOnlyDictionary<string, string> Names,
            IReadOnlyList<LanguageInfo> ServerLanguages,
            string? ClubLocale,
            string? UserLocale)
        {
            public static readonly State Empty = new(
                new Dictionary<string, IReadOnlyDictionary<string, string>>(),
                new Dictionary<string, IReadOnlyDictionary<string, string>>(),
                new Dictionary<string, long>(),
                new Dictionary<string, string>(),
                Array.Empty<LanguageInfo>(),
                null,
                null);
        }
    }
}
