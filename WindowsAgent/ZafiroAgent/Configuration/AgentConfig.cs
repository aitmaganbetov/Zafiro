using System;

namespace ZafiroAgent.Configuration
{
    public class AgentConfig
    {
        public string ClubId { get; set; } = string.Empty;
        public string ComputerId { get; set; } = string.Empty;
        public string ComputerNumber { get; set; } = string.Empty;
        public string Zone { get; set; } = string.Empty;
        public string DeviceName { get; set; } = string.Empty;
        public string DeviceId { get; set; } = string.Empty;

        public string ClubName { get; set; } = string.Empty;

        // Картинка клуба для экрана входа из последнего heartbeat: после перезапуска ПК сразу она.
        public string SignInImageUrl { get; set; } = string.Empty;

        // Язык клуба из последнего heartbeat: окно входа после перезапуска сразу на нём.
        public string ClubLocale { get; set; } = string.Empty;
        public string ApiBaseUrl { get; set; } = string.Empty;
        public string LoginEndpoint { get; set; } = string.Empty;

        // Секреты хранятся только в виде DPAPI (SecureStorageService).
        public string ProtectedClubKey { get; set; } = string.Empty;
        public string ProtectedApiToken { get; set; } = string.Empty;

        // Токен устройства (выдаётся сотруднику при регистрации ПК): heartbeat только этого ПК.
        public string ProtectedDeviceToken { get; set; } = string.Empty;
        public DateTime? TokenExpiresAtUtc { get; set; }
    }
}
