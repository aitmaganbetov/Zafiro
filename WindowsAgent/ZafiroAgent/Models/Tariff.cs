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
    }
}
