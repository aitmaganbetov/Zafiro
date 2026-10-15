using System;
using System.Reflection;

namespace ZafiroAgent.Services
{
    public static class DeviceInfo
    {
        public static string Name => Environment.MachineName;

        // UUID из SMBIOS; если это заглушка производителя (одинаковая на многих ПК) — MAC основного
        // адаптера с префиксом «MAC-». null — ПК опознать нельзя.
        public static string? Id
        {
            get
            {
                var probe = new WindowsHardwareProbe();
                return DeviceIdentity.Choose(probe.GetDeviceUuid(), Mac(probe));
            }
        }

        /// <summary>MAC основного сетевого адаптера (тот же, что агент сообщает серверу в heartbeat).</summary>
        public static string? MacAddress => Mac(new WindowsHardwareProbe());

        private static string? Mac(WindowsHardwareProbe probe)
        {
            try
            {
                return NetworkSelector.Select(probe.GetNetworkAdapters()).Mac;
            }
            catch (Exception)
            {
                return null;
            }
        }

        // UUID не меняется, пока процесс работает: WMI опрашивается один раз.
        private static readonly Lazy<string?> StableIdValue = new(() => Id);

        public static string? StableId => StableIdValue.Value;

        public static string AgentVersion => Assembly.GetExecutingAssembly().GetName().Version?.ToString(3) ?? "0.0.0";
    }
}
