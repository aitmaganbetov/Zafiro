using System.Collections.Generic;
using System.Linq;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace ZafiroAgent.Services
{
    /// <summary>
    /// Тема клуба (настройки → «Агент на ПК») из heartbeat: цвета шелла и экрана входа.
    /// null — стандартное оформление агента.
    /// </summary>
    public static class ShellThemes
    {
        /// <summary>accent — кнопки, accent_2 — выделение, soft — подзаголовки и ссылки,
        /// highlight — магазин, bg_0 и bg_1 — фон сверху вниз.</summary>
        public static readonly string[] Keys = { "accent", "accent_2", "soft", "highlight", "bg_0", "bg_1" };

        private static readonly Regex ColorPattern = new("^#[0-9A-Fa-f]{6}$", RegexOptions.CultureInvariant);

        /// <summary>
        /// false — сервер старой версии о теме не сообщает: сохранённую не трогаем.
        /// Тема без какого-нибудь цвета или с цветом не в формате #RRGGBB считается стандартной.
        /// </summary>
        public static bool TryRead(JsonElement club, out Dictionary<string, string>? theme)
        {
            theme = null;
            if (club.ValueKind != JsonValueKind.Object || !club.TryGetProperty("shell_theme", out var value)) return false;
            if (value.ValueKind != JsonValueKind.Object) return true;

            var colors = new Dictionary<string, string>();
            foreach (var key in Keys)
            {
                if (!value.TryGetProperty(key, out var color) || color.ValueKind != JsonValueKind.String ||
                    color.GetString() is not { } text || !ColorPattern.IsMatch(text)) return true;
                colors[key] = text.ToLowerInvariant();
            }
            theme = colors;
            return true;
        }

        public static bool Same(IReadOnlyDictionary<string, string>? a, IReadOnlyDictionary<string, string>? b) =>
            a is null || b is null ? a is null && b is null : Keys.All(key => a.GetValueOrDefault(key) == b.GetValueOrDefault(key));
    }
}
