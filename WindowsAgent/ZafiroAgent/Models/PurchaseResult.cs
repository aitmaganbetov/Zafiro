namespace ZafiroAgent.Models
{
    public class PurchaseResult
    {
        public bool Success { get; set; }
        public string? Message { get; set; }
        public decimal NewBalance { get; set; }
        public decimal NewBonus { get; set; }
    }
}
