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

        // Название и логотип клуба для закрытых мониторов; ClubReported=false — сервер старой версии.
        public bool ClubReported { get; set; }
        public string? ClubName { get; set; }
        public string? ClubLogoUrl { get; set; }

        // Язык клуба, включённые языки и версия переводов на сервере (агент перезагружает тексты при её смене).
        public string? ClubLocale { get; set; }
        public List<ZafiroAgent.Localization.LanguageInfo>? ClubLanguages { get; set; }
        public long? TranslationsVersion { get; set; }

        // Что делать с ПК после конца сессии (none, restart, shutdown) и через сколько секунд — из настроек клуба.
        public string? AfterSessionAction { get; set; }
        public int? AfterSessionDelaySeconds { get; set; }
    }
}
