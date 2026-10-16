using System;

namespace ZafiroAgent.Models
{
    public sealed record ClubInfo(long ClubId, string ClubName, Uri ApiBaseUrl, Uri LoginEndpoint);

    public sealed record ClubLogin(string Token, long ClubId, string ClubName, DateTime ExpiresAtUtc);

    public sealed record ClubSession(long ClubId, string ClubName, Uri ApiBaseUrl, string Token, DateTime ExpiresAtUtc);

    /// <summary>QR-код входа с телефона: ссылка в коде, сколько он живёт и как часто спрашивать сервер.</summary>
    /// <summary>Гостевая сессия ПК (оплачена на кассе без аккаунта): до EndsAtUtc по часам сервера.</summary>
    public sealed record GuestSession(string Id, DateTimeOffset EndsAtUtc, TimeSpan ClockOffset);

    public sealed record QrLoginCode(string Id, Uri Url, TimeSpan Lifetime, TimeSpan PollInterval);
}
