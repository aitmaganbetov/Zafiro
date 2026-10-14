namespace ZafiroAgent.Models
{
    public class Tariff
    {
        public string Id { get; set; } = string.Empty;
        public string Name { get; set; } = string.Empty;
        public string Description { get; set; } = string.Empty;
        public decimal Price { get; set; }
        public int Minutes { get; set; }
    }
}
