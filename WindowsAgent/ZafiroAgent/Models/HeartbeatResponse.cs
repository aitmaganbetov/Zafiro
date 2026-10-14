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

        // Оплаченная сессия по данным сервера; SessionReported=false — сервер старой версии.
        public bool SessionReported { get; set; }
        public string? SessionId { get; set; }
        public DateTimeOffset? SessionEndsAtUtc { get; set; }
        public bool SessionExpired { get; set; }

        // Время сервера в момент ответа: по нему агент поправляет часы ПК при подсчёте конца сессии.
        public DateTimeOffset? ServerTimeUtc { get; set; }
    }
}
