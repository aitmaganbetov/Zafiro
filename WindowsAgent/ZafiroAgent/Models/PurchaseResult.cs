using System;
using System.Text.Json.Serialization;

namespace ZafiroAgent.Models
{
    [JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    public class PurchaseResult
    {
        [JsonPropertyName("success")]
        public bool Success { get; set; }

        [JsonPropertyName("message")]
        public string? Message { get; set; }

        [JsonPropertyName("new_balance")]
        public decimal NewBalance { get; set; }

        [JsonPropertyName("new_bonus")]
        public decimal NewBonus { get; set; }

        [JsonPropertyName("minutes_added")]
        public int MinutesAdded { get; set; }

        [JsonPropertyName("session_ends_at")]
        public DateTimeOffset? SessionEndsAt { get; set; }
    }
}
