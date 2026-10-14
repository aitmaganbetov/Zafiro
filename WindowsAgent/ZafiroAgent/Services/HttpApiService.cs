using System;
using System.Collections.Generic;
using System.Globalization;
using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
using ZafiroAgent.Configuration;
using ZafiroAgent.Models;
using ZafiroAgent.Security;

namespace ZafiroAgent.Services
{
    public sealed class HttpApiService : IApiService
    {
        private const string DefaultBaseUrl = "https://zafiro.kz";

        private readonly HttpClient _http;
        private readonly ConfigurationService _config;
        private readonly SecureStorageService _secure;

        public HttpApiService(HttpClient? http = null, ConfigurationService? config = null, SecureStorageService? secure = null)
        {
            // Без редиректов: токен и пароль не уходят на другой хост.
            _http = http ?? ZafiroApiClient.CreateHttpClient();
            _config = config ?? new ConfigurationService();
            _secure = secure ?? new SecureStorageService();
        }

        public async Task<SessionInfo> GetSessionInfoAsync(string sessionId, CancellationToken cancellationToken)
        {
            var token = _secure.Unprotect(_config.Load().ProtectedApiToken);
            // Без входа баланса нет: выдуманный нулевой профиль выглядел бы как настоящий.
            if (string.IsNullOrWhiteSpace(token))
                throw new InvalidOperationException("Not signed in.");

            var uri = new Uri($"{ApiRoot(_config.Load())}/me");
            using var request = new HttpRequestMessage(HttpMethod.Get, uri);
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);

            using var response = await _http.SendAsync(request, cancellationToken).ConfigureAwait(false);
            var payload = await response.Content.ReadAsStringAsync(cancellationToken).ConfigureAwait(false);
            if (!response.IsSuccessStatusCode)
            {
                throw new HttpRequestException($"Profile request failed: {(int)response.StatusCode}", null, response.StatusCode);
            }

            var profile = ParseCurrentUserProfile(payload);
            return new SessionInfo
            {
                SessionId = string.IsNullOrWhiteSpace(profile.SessionId) ? sessionId : profile.SessionId,
                UserName = profile.UserName,
                Balance = profile.Balance,
                BonusBalance = profile.BonusBalance,
                TariffName = profile.TariffName,
                StartedAt = profile.StartedAt,
                Remaining = profile.Remaining
            };
        }

        public async Task<Tariff[]> GetTariffsAsync(CancellationToken cancellationToken)
        {
            var config = _config.Load();
            var token = _secure.Unprotect(config.ProtectedApiToken);
            if (string.IsNullOrWhiteSpace(token))
                throw new UnauthorizedAccessException("Выполните вход, чтобы загрузить тарифы.");

            var tariffsUri = new Uri($"{ApiRoot(config)}/tariffs");
            using var request = new HttpRequestMessage(HttpMethod.Get, tariffsUri);
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
            using var response = await _http.SendAsync(request, cancellationToken).ConfigureAwait(false);
            var payload = await response.Content.ReadAsStringAsync(cancellationToken).ConfigureAwait(false);
            if (!response.IsSuccessStatusCode)
                throw new HttpRequestException(ExtractErrorMessage(payload) ?? "Не удалось загрузить тарифы.", null, response.StatusCode);

            using var document = JsonDocument.Parse(payload);
            var root = document.RootElement;
            var items = root.ValueKind == JsonValueKind.Object && root.TryGetProperty("data", out var data) ? data : root;
            return items.ValueKind == JsonValueKind.Array
                ? JsonSerializer.Deserialize<Tariff[]>(items.GetRawText()) ?? Array.Empty<Tariff>()
                : Array.Empty<Tariff>();
        }

        public async Task<Product[]> GetProductsAsync(CancellationToken cancellationToken)
        {
            var productsUri = new Uri($"{ApiRoot(_config.Load())}/products");

            using var request = new HttpRequestMessage(HttpMethod.Get, productsUri);
            var token = _secure.Unprotect(_config.Load().ProtectedApiToken);
            if (!string.IsNullOrWhiteSpace(token))
                request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);

            using var response = await _http.SendAsync(request, cancellationToken).ConfigureAwait(false);
            response.EnsureSuccessStatusCode();
            var payload = await response.Content.ReadAsStringAsync(cancellationToken).ConfigureAwait(false);
            using var document = JsonDocument.Parse(payload);
            var root = document.RootElement;
            var items = root.ValueKind == JsonValueKind.Object && root.TryGetProperty("data", out var data)
                ? data
                : root;

            if (items.ValueKind != JsonValueKind.Array)
                return Array.Empty<Product>();

            return JsonSerializer.Deserialize<Product[]>(items.GetRawText()) ?? Array.Empty<Product>();
        }

        public async Task<PurchaseResult> PurchaseTariffAsync(string tariffId, CancellationToken cancellationToken)
        {
            var config = _config.Load();
            var token = _secure.Unprotect(config.ProtectedApiToken);
            if (string.IsNullOrWhiteSpace(token))
            {
                return new PurchaseResult { Success = false, Message = "Выполните вход, чтобы купить тариф." };
            }
            if (!long.TryParse(config.ComputerId, NumberStyles.None, CultureInfo.InvariantCulture, out var computerId) || computerId <= 0)
            {
                return new PurchaseResult { Success = false, Message = "Компьютер ещё не зарегистрирован в клубе. Проверьте подключение агента." };
            }
            var purchaseUri = new Uri($"{ApiRoot(config)}/tariffs/{Uri.EscapeDataString(tariffId)}/purchase");
            var payload = JsonSerializer.Serialize(new { computer_id = computerId });
            using var request = new HttpRequestMessage(HttpMethod.Post, purchaseUri)
            {
                Content = new StringContent(payload, Encoding.UTF8, "application/json")
            };
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
            // Токен устройства подтверждает, что тариф покупают на этом самом ПК.
            var deviceToken = await Task.Run(() => DeviceTokens.Resolve(_secure.Unprotect(config.ProtectedDeviceToken)), cancellationToken).ConfigureAwait(false);
            if (!string.IsNullOrEmpty(deviceToken))
                request.Headers.Add("X-Agent-Token", deviceToken);
            using var response = await _http.SendAsync(request, cancellationToken).ConfigureAwait(false);
            var responseBody = await response.Content.ReadAsStringAsync(cancellationToken).ConfigureAwait(false);
            if (!response.IsSuccessStatusCode)
                return new PurchaseResult { Success = false, Message = ExtractErrorMessage(responseBody) ?? "Не удалось купить тариф." };

            return JsonSerializer.Deserialize<PurchaseResult>(responseBody)
                ?? new PurchaseResult { Success = false, Message = "Сервер вернул неожиданный ответ." };
        }

        /// <summary>Досрочно завершает оплаченную сессию на сервере (неиспользованное время не возвращается).</summary>
        public async Task<bool> EndSessionAsync(string sessionId, CancellationToken cancellationToken)
        {
            var config = _config.Load();
            var token = _secure.Unprotect(config.ProtectedApiToken);
            if (string.IsNullOrWhiteSpace(token) || string.IsNullOrWhiteSpace(sessionId)) return false;
            if (!long.TryParse(config.ComputerId, NumberStyles.None, CultureInfo.InvariantCulture, out var computerId) || computerId <= 0) return false;

            var uri = new Uri($"{ApiRoot(config)}/club-computers/{computerId.ToString(CultureInfo.InvariantCulture)}/session/end");
            using var request = new HttpRequestMessage(HttpMethod.Post, uri)
            {
                Content = new StringContent(JsonSerializer.Serialize(new { session_id = sessionId }), Encoding.UTF8, "application/json")
            };
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
            using var response = await _http.SendAsync(request, cancellationToken).ConfigureAwait(false);

            return response.IsSuccessStatusCode;
        }

        /// <summary>Корень API (https://zafiro.kz/api): адрес из настроек используется, только если он проверен.</summary>
        private static string ApiRoot(AgentConfig config)
        {
            var configured = config.ApiBaseUrl?.TrimEnd('/');
            var root = ZafiroApiClient.TryValidateHttpsEndpoint(configured, out _) ? configured! : DefaultBaseUrl;

            return root.EndsWith("/api", StringComparison.OrdinalIgnoreCase) ? root : root + "/api";
        }

        private static string? ExtractErrorMessage(string payload)
        {
            if (string.IsNullOrWhiteSpace(payload)) return null;

            try
            {
                using var document = JsonDocument.Parse(payload);
                var root = document.RootElement;
                if (root.ValueKind != JsonValueKind.Object) return null;

                var message = ExtractString(root, "message", "error", "detail", "error_description");
                if (!string.IsNullOrWhiteSpace(message)) return message;

                if (root.TryGetProperty("errors", out var errors) && errors.ValueKind == JsonValueKind.Object)
                {
                    foreach (var key in new[] { "phone", "login", "password", "email" })
                    {
                        if (errors.TryGetProperty(key, out var val) && val.ValueKind == JsonValueKind.Array)
                        {
                            var first = val.EnumerateArray().FirstOrDefault().GetString();
                            if (!string.IsNullOrWhiteSpace(first)) return first;
                        }
                    }
                }
            }
            catch (JsonException)
            {
                return null;
            }

            return null;
        }

        private static SessionInfo ParseCurrentUserProfile(string payload)
        {
            using var document = JsonDocument.Parse(payload);
            var root = document.RootElement;

            if (root.ValueKind != JsonValueKind.Object)
                return new SessionInfo
                {
                    SessionId = string.Empty,
                    UserName = "Пользователь",
                    Balance = 0m,
                    BonusBalance = 0m,
                    TariffName = null,
                    StartedAt = null,
                    Remaining = null
                };

            var userId = GetDecimal(root, "user_id");
            var name = ExtractString(root, "name", "user_name", "full_name", "display_name") ?? "Пользователь";
            var balance = GetDecimal(root, "balance");
            var bonusBalance = GetDecimal(root, "bonus_balance", "bonusBalance");

            return new SessionInfo
            {
                SessionId = userId != null ? userId.Value.ToString(CultureInfo.InvariantCulture) : string.Empty,
                UserName = name,
                Balance = balance ?? 0m,
                BonusBalance = bonusBalance ?? 0m,
                TariffName = null,
                StartedAt = null,
                Remaining = null
            };
        }

        private static decimal? GetDecimal(JsonElement root, params string[] names)
        {
            foreach (var name in names)
            {
                if (root.TryGetProperty(name, out var value))
                {
                    if (value.ValueKind == JsonValueKind.Number && value.TryGetDecimal(out var amount))
                        return amount;

                    if (value.ValueKind == JsonValueKind.String && decimal.TryParse(value.GetString(), NumberStyles.Any, CultureInfo.InvariantCulture, out var parsed))
                        return parsed;
                }
            }
            return null;
        }

        private static string? ExtractString(JsonElement root, params string[] names)
        {
            foreach (var name in names)
            {
                if (root.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.String)
                {
                    var text = value.GetString();
                    if (!string.IsNullOrWhiteSpace(text)) return text;
                }
            }
            return null;
        }

    }
}
