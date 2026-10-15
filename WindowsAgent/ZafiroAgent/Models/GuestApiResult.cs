using System.Text.Json;

namespace ZafiroAgent.Models
{
    /// <summary>Ответ /api/guest/…: JSON сервера или сообщение об ошибке для клиента.</summary>
    public sealed record GuestApiResult(bool Success, JsonElement? Data, string? Message)
    {
        public static GuestApiResult Ok(JsonElement data) =>
            new(true, data, data.TryGetProperty("message", out var message) && message.ValueKind == JsonValueKind.String ? message.GetString() : null);

        public static GuestApiResult Fail(string message) => new(false, null, message);
    }
}
