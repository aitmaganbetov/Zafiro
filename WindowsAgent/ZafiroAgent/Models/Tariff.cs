using System.Text.Json.Serialization;

namespace ZafiroAgent.Models
{
    [JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    public class Tariff
    {
        [JsonPropertyName("id")]
        public string Id { get; set; } = string.Empty;

        [JsonPropertyName("name")]
        public string Name { get; set; } = string.Empty;

        [JsonPropertyName("description")]
        public string Description { get; set; } = string.Empty;

        [JsonPropertyName("price")]
        public decimal Price { get; set; }

        [JsonPropertyName("minutes")]
        public int Minutes { get; set; }

        [JsonPropertyName("currency")]
        public string Currency { get; set; } = "KZT";

        // Можно ли оплатить бонусами и какую часть цены (настройка клуба; «только основной баланс» — нельзя).
        [JsonPropertyName("bonus_allowed")]
        public bool BonusAllowed { get; set; }

        [JsonPropertyName("bonus_max_percent")]
        public int BonusMaxPercent { get; set; }

        // Смешанная оплата в клубе: бонусы до процента, остальное с основного; иначе бонусами вся цена.
        [JsonPropertyName("mixed_payment")]
        public bool MixedPayment { get; set; }

        // Ограничение использования: неиспользованное время сохраняется абонементом на столько минут после покупки.
        [JsonPropertyName("usage_limit_minutes")]
        public int? UsageLimitMinutes { get; set; }
    }
}
