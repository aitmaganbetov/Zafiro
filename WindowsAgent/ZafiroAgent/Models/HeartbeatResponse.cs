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

        // Что клиент может делать сам (настройки клуба «Гости»): qr_login, self_transfer, friends, balance_transfer.
        public Dictionary<string, bool>? GuestFeatures { get; set; }

        // Через сколько минут без оплаченной сессии вошедший клиент выходит из аккаунта (0 — не выходит).
        public int? IdleLogoutMinutes { get; set; }

        // Вошедший клиент (агент отправил его токен вместе с heartbeat): баланс и что у него нового.
        // null — сервер старой версии: агент опрашивает баланс, друзей и чат сам, как раньше.
        public CustomerState? Customer { get; set; }
    }

    /// <summary>Баланс клиента, число входящих заявок в друзья и id последнего ответа сотрудника в чате.</summary>
    public sealed record CustomerState(decimal Balance, decimal BonusBalance, int FriendRequests, long ChatLastStaffId);
}
