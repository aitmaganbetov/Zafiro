using System;

namespace ZafiroAgent.Models
{
    public class HeartbeatResponse
    {
        public string? Status { get; set; }
        public string? ConnectionStatus { get; set; }
        public DateTime? LastSeenAt { get; set; }
        public int RecommendedIntervalSeconds { get; set; }
        public string? ComputerId { get; set; }
        public string? Number { get; set; }
        public string? Zone { get; set; }
    }
}
