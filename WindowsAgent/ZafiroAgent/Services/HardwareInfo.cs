using System;
using System.Collections.Generic;
using System.Linq;
using System.Net;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Text;
using System.Text.RegularExpressions;

namespace ZafiroAgent.Services
{
    public sealed record NetworkAdapterInfo(
        string Name,
        string Description,
        NetworkInterfaceType Type,
        OperationalStatus Status,
        byte[]? PhysicalAddress,
        IReadOnlyList<IPAddress> Addresses,
        bool HasGateway);

    // Недоступные значения остаются null: выдуманные данные не подставляются.
    public sealed record HardwareSnapshot(
        string? MacAddress,
        string? LocalIp,
        string? CpuModel,
        int? CpuCores,
        long? RamTotalMb,
        string? GpuModel,
        string? OsVersion)
    {
        public static HardwareSnapshot Empty { get; } = new(null, null, null, null, null, null, null);
    }

    public interface IHardwareProbe
    {
        string? GetDeviceUuid();
        string? GetCpuModel();
        int? GetLogicalProcessorCount();
        long? GetTotalRamBytes();
        IReadOnlyList<string> GetGpuNames();
        string? GetOsCaption();
        IReadOnlyList<NetworkAdapterInfo> GetNetworkAdapters();
    }

    /// <summary>
    /// Идентификатор ПК для сервера. Обычно — UUID из SMBIOS. У дешёвых плат UUID бывает
    /// заглушкой, одинаковой на всех ПК клуба: тогда идентификатором служит MAC основного адаптера
    /// (в бездисковой системе ПК и так различаются по MAC).
    /// </summary>
    public static class DeviceIdentity
    {
        // Заглушки производителей, которые встречаются на многих платах сразу.
        private static readonly HashSet<string> PlaceholderUuids = new(StringComparer.OrdinalIgnoreCase)
        {
            "03000200-0400-0500-0006-000700080009",
            "00020003-0004-0005-0006-000700080009",
            "12345678-1234-5678-90AB-CDDEEFAABBCC",
            "01234567-89AB-CDEF-0123-456789ABCDEF",
        };

        public static bool IsUsableUuid(string? uuid)
        {
            if (string.IsNullOrWhiteSpace(uuid)) return false;
            var trimmed = uuid.Trim();
            if (PlaceholderUuids.Contains(trimmed)) return false;

            // 00000000-…, FFFFFFFF-…, 11111111-… и подобные: одна-две разные цифры на весь UUID.
            var digits = trimmed.Replace("-", string.Empty).ToUpperInvariant();
            return digits.Length >= 16 && digits.Distinct().Count() > 2;
        }

        public static string? Choose(string? uuid, string? mac)
        {
            if (IsUsableUuid(uuid)) return uuid!.Trim();

            var hex = new string((mac ?? string.Empty).Where(Uri.IsHexDigit).ToArray()).ToUpperInvariant();
            return hex.Length == 12 && hex.Distinct().Count() > 1 ? "MAC-" + hex : null;
        }
    }

    public static class NetworkSelector
    {
        private static readonly string[] VirtualMarkers =
        {
            "virtual", "vmware", "vbox", "hyper-v", "vethernet", "bluetooth", "loopback", "tap-", "tunnel", "pseudo", "wsl"
        };

        /// <summary>Активный физический адаптер: MAC и предпочтительный LAN-адрес.</summary>
        public static (string? Mac, string? Ip) Select(IEnumerable<NetworkAdapterInfo> adapters)
        {
            var candidates = adapters
                .Where(a => a.Status == OperationalStatus.Up && IsPhysicalType(a.Type) && !LooksVirtual(a))
                .Select(a => (Adapter: a, Ip: PickAddress(a.Addresses)))
                .Where(x => x.Ip is not null)
                .OrderByDescending(x => x.Adapter.HasGateway)
                .ThenByDescending(x => x.Ip!.AddressFamily == AddressFamily.InterNetwork)
                .ToList();

            if (candidates.Count == 0) return (null, null);

            var best = candidates[0];
            return (FormatMac(best.Adapter.PhysicalAddress), best.Ip!.ToString());
        }

        public static string? FormatMac(byte[]? bytes)
        {
            if (bytes is null || bytes.Length != 6 || bytes.All(b => b == 0)) return null;
            return string.Join(':', bytes.Select(b => b.ToString("X2")));
        }

        // Предпочитаем IPv4 без APIPA; IPv6 — только не link-local.
        public static IPAddress? PickAddress(IEnumerable<IPAddress> addresses)
        {
            var list = addresses.Where(a => !IPAddress.IsLoopback(a)).ToList();

            var v4 = list.FirstOrDefault(a => a.AddressFamily == AddressFamily.InterNetwork && !IsApipa(a));
            if (v4 is not null) return v4;

            return list.FirstOrDefault(a => a.AddressFamily == AddressFamily.InterNetworkV6 && !a.IsIPv6LinkLocal && !a.IsIPv6Multicast);
        }

        private static bool IsApipa(IPAddress address)
        {
            var bytes = address.GetAddressBytes();
            return bytes.Length == 4 && bytes[0] == 169 && bytes[1] == 254;
        }

        private static bool IsPhysicalType(NetworkInterfaceType type) =>
            type is NetworkInterfaceType.Ethernet or NetworkInterfaceType.GigabitEthernet or NetworkInterfaceType.FastEthernetT
                or NetworkInterfaceType.FastEthernetFx or NetworkInterfaceType.Wireless80211 or NetworkInterfaceType.Wman
                or NetworkInterfaceType.Wwanpp or NetworkInterfaceType.Wwanpp2;

        private static bool LooksVirtual(NetworkAdapterInfo adapter)
        {
            var text = (adapter.Name + " " + adapter.Description).ToLowerInvariant();
            return VirtualMarkers.Any(text.Contains);
        }
    }

    /// <summary>
    /// Кэширует характеристики ПК: аппаратная часть читается редко, сеть — чаще.
    /// Любая ошибка зонда превращается в null и никогда не бросается наружу.
    /// </summary>
    public sealed class HardwareCollector
    {
        public const int MaxTextLength = 255;

        private readonly IHardwareProbe _probe;
        private readonly Func<DateTime> _utcNow;
        private readonly TimeSpan _hardwareTtl;
        private readonly TimeSpan _networkTtl;
        private readonly object _lock = new();

        private HardwareSnapshot _snapshot = HardwareSnapshot.Empty;
        private DateTime? _hardwareAt;
        private DateTime? _networkAt;

        public HardwareCollector(IHardwareProbe probe, Func<DateTime>? utcNow = null, TimeSpan? hardwareTtl = null, TimeSpan? networkTtl = null)
        {
            _probe = probe;
            _utcNow = utcNow ?? (() => DateTime.UtcNow);
            _hardwareTtl = hardwareTtl ?? TimeSpan.FromHours(6);
            _networkTtl = networkTtl ?? TimeSpan.FromSeconds(30);
        }

        public HardwareSnapshot GetSnapshot()
        {
            lock (_lock)
            {
                var now = _utcNow();

                if (_hardwareAt is null || now - _hardwareAt >= _hardwareTtl)
                {
                    _snapshot = _snapshot with
                    {
                        CpuModel = Limit(Safe(_probe.GetCpuModel)),
                        CpuCores = Safe(_probe.GetLogicalProcessorCount) is { } cores and > 0 ? cores : null,
                        RamTotalMb = Safe(_probe.GetTotalRamBytes) is { } bytes and > 0 ? (long)Math.Round(bytes / 1048576.0) : null,
                        GpuModel = JoinGpuNames(Safe(_probe.GetGpuNames)),
                        OsVersion = Limit(NormalizeOs(Safe(_probe.GetOsCaption)))
                    };
                    _hardwareAt = now;
                }

                if (_networkAt is null || now - _networkAt >= _networkTtl)
                {
                    var (mac, ip) = NetworkSelector.Select(Safe(_probe.GetNetworkAdapters) ?? Array.Empty<NetworkAdapterInfo>());
                    _snapshot = _snapshot with { MacAddress = mac, LocalIp = ip };
                    _networkAt = now;
                }

                return _snapshot;
            }
        }

        public static string? JoinGpuNames(IEnumerable<string>? names)
        {
            if (names is null) return null;

            var joined = string.Join("; ", names
                .Select(CollapseWhitespace)
                .Where(n => n.Length > 0)
                .Distinct(StringComparer.OrdinalIgnoreCase));

            return joined.Length == 0 ? null : Limit(joined);
        }

        private static string? NormalizeOs(string? caption)
        {
            var text = CollapseWhitespace(caption);
            foreach (var prefix in new[] { "Microsoft ", "Майкрософт " })
            {
                if (text.StartsWith(prefix, StringComparison.OrdinalIgnoreCase))
                {
                    text = text[prefix.Length..];
                    break;
                }
            }

            return text.Length == 0 ? null : text;
        }

        private static string? Limit(string? value)
        {
            var text = CollapseWhitespace(value);
            if (text.Length == 0) return null;
            return text.Length <= MaxTextLength ? text : text[..MaxTextLength];
        }

        private static string CollapseWhitespace(string? value) =>
            value is null ? string.Empty : Regex.Replace(value, @"\s+", " ").Trim();

        private static T? Safe<T>(Func<T?> read)
        {
            try
            {
                return read();
            }
            catch
            {
                return default;
            }
        }
    }
}
