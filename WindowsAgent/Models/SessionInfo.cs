using System;

namespace ZafiroAgent.Models
{
    public class SessionInfo
    {
        public string SessionId { get; set; } = string.Empty;
        public string UserName { get; set; } = string.Empty;
        public decimal Balance { get; set; }
        public decimal BonusBalance { get; set; }
        public string? TariffName { get; set; }
        public DateTime? StartedAt { get; set; }
        public TimeSpan? Remaining { get; set; }
    }
}
