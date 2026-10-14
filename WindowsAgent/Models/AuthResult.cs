using System;

namespace ZafiroAgent.Models
{
    public class AuthResult
    {
        public bool Success { get; set; }
        public string? Message { get; set; }
        public string? SessionId { get; set; }
        public string? Token { get; set; }
        public string? UserName { get; set; }
        public DateTime? ExpiresAt { get; set; }
    }
}
