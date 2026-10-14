using System;
using System.Collections.Generic;
using System.Linq;
using System.Management;
using System.Net.NetworkInformation;

namespace ZafiroAgent.Services
{
    /// <summary>Локальный сбор данных средствами Windows (WMI и NetworkInterface); данные никуда, кроме API Zafiro, не отправляются.</summary>
    public sealed class WindowsHardwareProbe : IHardwareProbe
    {
        private static readonly TimeSpan QueryTimeout = TimeSpan.FromSeconds(10);

        public string? GetDeviceUuid()
        {
            var uuid = QueryFirst("SELECT UUID FROM Win32_ComputerSystemProduct", "UUID")?.Trim();
            if (string.IsNullOrEmpty(uuid)) return null;

            // Пустые SMBIOS-значения не годятся как идентификатор машины.
            var digits = uuid.Replace("-", string.Empty);
            return digits.All(c => c is '0' or 'F' or 'f') ? null : uuid;
        }

        public string? GetCpuModel() => QueryFirst("SELECT Name FROM Win32_Processor", "Name");

        public int? GetLogicalProcessorCount() => Environment.ProcessorCount > 0 ? Environment.ProcessorCount : null;

        public long? GetTotalRamBytes() =>
            long.TryParse(QueryFirst("SELECT TotalPhysicalMemory FROM Win32_ComputerSystem", "TotalPhysicalMemory"), out var bytes) ? bytes : null;

        public IReadOnlyList<string> GetGpuNames() => QueryAll("SELECT Name FROM Win32_VideoController", "Name");

        public string? GetOsCaption() => QueryFirst("SELECT Caption FROM Win32_OperatingSystem", "Caption");

        public IReadOnlyList<NetworkAdapterInfo> GetNetworkAdapters()
        {
            var result = new List<NetworkAdapterInfo>();
            foreach (var nic in NetworkInterface.GetAllNetworkInterfaces())
            {
                try
                {
                    var properties = nic.GetIPProperties();
                    result.Add(new NetworkAdapterInfo(
                        nic.Name,
                        nic.Description,
                        nic.NetworkInterfaceType,
                        nic.OperationalStatus,
                        nic.GetPhysicalAddress().GetAddressBytes(),
                        properties.UnicastAddresses.Select(a => a.Address).ToList(),
                        properties.GatewayAddresses.Any(g => !g.Address.Equals(System.Net.IPAddress.Any))));
                }
                catch
                {
                    // Проблемный адаптер пропускаем.
                }
            }

            return result;
        }

        private static string? QueryFirst(string query, string property) => QueryAll(query, property).FirstOrDefault();

        private static IReadOnlyList<string> QueryAll(string query, string property)
        {
            var values = new List<string>();
            try
            {
                using var searcher = new ManagementObjectSearcher(query) { Options = { Timeout = QueryTimeout } };
                foreach (var item in searcher.Get())
                {
                    using (item)
                    {
                        var value = item[property]?.ToString();
                        if (!string.IsNullOrWhiteSpace(value)) values.Add(value);
                    }
                }
            }
            catch
            {
                // WMI может быть недоступен или отключён: значения остаются null.
            }

            return values;
        }
    }
}
