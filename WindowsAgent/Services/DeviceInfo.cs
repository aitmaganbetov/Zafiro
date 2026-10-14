using System;
using System.Reflection;

namespace ZafiroAgent.Services
{
    public static class DeviceInfo
    {
        public static string Name => Environment.MachineName;

        // UUID из SMBIOS (Win32_ComputerSystemProduct); null, если получить его нельзя. MAC как идентификатор не используется.
        public static string? Id => new WindowsHardwareProbe().GetDeviceUuid();

        public static string AgentVersion => Assembly.GetExecutingAssembly().GetName().Version?.ToString(3) ?? "0.0.0";
    }
}
