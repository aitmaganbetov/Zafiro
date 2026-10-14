using System;

namespace ZafiroAgent.Configuration
{
    public class AgentConfig
    {
        public string ApiUrl { get; set; } = string.Empty;
        public string ProtectedAgentKey { get; set; } = string.Empty;
        public string ClubId { get; set; } = string.Empty;
        public string ComputerId { get; set; } = string.Empty;
        public string ComputerNumber { get; set; } = string.Empty;
        public string Zone { get; set; } = string.Empty;
        public string DeviceName { get; set; } = string.Empty;
        public string DeviceId { get; set; } = string.Empty;

        public string ClubName { get; set; } = string.Empty;
        public string ApiBaseUrl { get; set; } = string.Empty;
        public string LoginEndpoint { get; set; } = string.Empty;

        // Секреты хранятся только в виде DPAPI (SecureStorageService).
        public string ProtectedClubKey { get; set; } = string.Empty;
        public string ProtectedApiToken { get; set; } = string.Empty;
        public DateTime? TokenExpiresAtUtc { get; set; }
    }
}
