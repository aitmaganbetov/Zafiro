using System.Collections.Generic;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace ZafiroAgent.Services
{
    /// <summary>
    /// Команды сотрудников из ответа heartbeat (агент просит их полем commands): перезагрузить или
    /// выключить этот ПК и MAC-адреса выключенных ПК клуба, которые этот ПК будит (Wake-on-LAN).
    /// </summary>
    public static class StaffCommands
    {
        private static readonly Regex MacPattern = new("^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$", RegexOptions.CultureInvariant);
        private const int MaxWakeTargets = 50;

        /// <summary>"restart", "shutdown", "service" (техрежим, как Ctrl+Alt+P с PIN) или null.</summary>
        public static string? PowerCommand(JsonElement root) =>
            root.ValueKind == JsonValueKind.Object && root.TryGetProperty("power_command", out var value) && value.ValueKind == JsonValueKind.String
            && value.GetString() is "restart" or "shutdown" or "service" ? value.GetString() : null;

        public static IReadOnlyList<string> WakeMacs(JsonElement root)
        {
            var macs = new List<string>();
            if (root.ValueKind != JsonValueKind.Object || !root.TryGetProperty("wake_macs", out var list) || list.ValueKind != JsonValueKind.Array) return macs;

            foreach (var item in list.EnumerateArray())
            {
                if (macs.Count >= MaxWakeTargets) break;
                if (item.ValueKind == JsonValueKind.String && item.GetString() is { } mac && MacPattern.IsMatch(mac.ToUpperInvariant()))
                    macs.Add(mac.ToUpperInvariant());
            }

            return macs;
        }
    }
}
