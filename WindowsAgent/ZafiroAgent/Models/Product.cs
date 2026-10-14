using System.Text.Json.Serialization;

namespace ZafiroAgent.Models
{
    public sealed class Product
    {
        [JsonPropertyName("id")]
        public int Id { get; set; }

        [JsonPropertyName("sku")]
        public string Sku { get; set; } = string.Empty;

        [JsonPropertyName("name")]
        public string Name { get; set; } = string.Empty;

        [JsonPropertyName("category")]
        public string Category { get; set; } = string.Empty;

        [JsonPropertyName("price")]
        public decimal Price { get; set; }

        [JsonPropertyName("currency")]
        public string Currency { get; set; } = "KZT";

        [JsonPropertyName("quantity")]
        public int Quantity { get; set; }

        [JsonPropertyName("in_stock")]
        public bool InStock { get; set; }

        [JsonPropertyName("image_url")]
        public string ImageUrl { get; set; } = string.Empty;
    }
}