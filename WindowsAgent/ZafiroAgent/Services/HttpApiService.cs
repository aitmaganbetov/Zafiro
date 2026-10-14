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
        private static readonly Uri DefaultLoginEndpoint = new($"{DefaultBaseUrl}/api/agent/user/login");

        private readonly HttpClient _http;
        private readonly Uri _loginEndpoint;
        private readonly ConfigurationService _config;
        private readonly SecureStorageService _secure;

        public HttpApiService(HttpClient? http = null, string? baseUrl = null, ConfigurationService? config = null, SecureStorageService? secure = null)
        {
            _http = http ?? new HttpClient { Timeout = TimeSpan.FromSeconds(20) };
            var root = string.IsNullOrWhiteSpace(baseUrl) ? DefaultBaseUrl : baseUrl.TrimEnd('/');
            _loginEndpoint = new Uri($"{root}/api/agent/user/login");
            _config = config ?? new ConfigurationService();
            _secure = secure ?? new SecureStorageService();
        }

        public async Task<AuthResult> AuthenticateAsync(string login, string password, CancellationToken cancellationToken)
        {
            if (string.IsNullOrWhiteSpace(login) || string.IsNullOrWhiteSpace(password))
            {
                return new AuthResult { Success = false, Message = "Введите телефон и пароль." };
            }

            var body = new Dictionary<string, object?>
            {
                ["phone"] = login.Trim(),
                ["login"] = login.Trim(),
                ["password"] = password
            };

            try
            {
                using var content = new StringContent(JsonSerializer.Serialize(body), Encoding.UTF8, "application/json");
                using var response = await _http.PostAsync(_loginEndpoint, content, cancellationToken).ConfigureAwait(false);
                var payload = await response.Content.ReadAsStringAsync(cancellationToken).ConfigureAwait(false);

                if (!response.IsSuccessStatusCode)
                    return new AuthResult { Success = false, Message = ExtractErrorMessage(payload) ?? "Не удалось выполнить вход." };

                return ParseAuthResponse(payload);
            }
            catch (JsonException)
            {
                return new AuthResult { Success = false, Message = "Сервер Zafiro вернул неожиданную ошибку." };
            }
            catch (HttpRequestException)
            {
                return new AuthResult { Success = false, Message = "Не удалось подключиться к серверу Zafiro." };
            }
            catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
            {
                return new AuthResult { Success = false, Message = "Превышено время ожидания сервера Zafiro." };
            }
        }

        public async Task<bool> CheckServerReachableAsync(CancellationToken cancellationToken)
        {
            try
            {
                using var response = await _http.GetAsync(DefaultLoginEndpoint, cancellationToken).ConfigureAwait(false);
                return response.StatusCode == HttpStatusCode.NotFound || response.StatusCode == HttpStatusCode.OK || response.StatusCode == HttpStatusCode.Unauthorized || response.StatusCode == HttpStatusCode.Forbidden;
            }
            catch
            {
                return false;
            }
        }

        public async Task<SessionInfo> GetSessionInfoAsync(string sessionId, CancellationToken cancellationToken)
        {
            var token = _secure.Unprotect(_config.Load().ProtectedApiToken);
            if (string.IsNullOrWhiteSpace(token))
            {
                return new SessionInfo
                {
                    SessionId = sessionId,
                    UserName = "Пользователь",
                    Balance = 0m,
                    BonusBalance = 0m,
                    TariffName = null,
                    StartedAt = DateTime.UtcNow,
                    Remaining = TimeSpan.Zero
                };
            }

            var endpoint = _config.Load().ApiBaseUrl;
            if (string.IsNullOrWhiteSpace(endpoint))
                endpoint = DefaultBaseUrl;

            var baseUrl = endpoint.TrimEnd('/');
            var uri = new Uri(baseUrl.EndsWith("/api", StringComparison.OrdinalIgnoreCase)
                ? $"{baseUrl}/me"
                : $"{baseUrl}/api/me");
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

            var endpoint = string.IsNullOrWhiteSpace(config.ApiBaseUrl) ? DefaultBaseUrl : config.ApiBaseUrl.TrimEnd('/');
            var tariffsUri = new Uri(endpoint.EndsWith("/api", StringComparison.OrdinalIgnoreCase)
                ? $"{endpoint}/tariffs"
                : $"{endpoint}/api/tariffs");
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
            var configuredBaseUrl = _config.Load().ApiBaseUrl;
            var baseUrl = string.IsNullOrWhiteSpace(configuredBaseUrl) ? DefaultBaseUrl : configuredBaseUrl.TrimEnd('/');
            var productsUri = new Uri(baseUrl.EndsWith("/api", StringComparison.OrdinalIgnoreCase)
                ? $"{baseUrl}/products"
                : $"{baseUrl}/api/products");

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

        public async Task<PurchaseResult> PurchaseTariffAsync(string sessionId, string tariffId, CancellationToken cancellationToken)
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
            if (string.IsNullOrWhiteSpace(sessionId))
            {
                return new PurchaseResult { Success = false, Message = "Войдите в аккаунт перед покупкой тарифа." };
            }

            var endpoint = string.IsNullOrWhiteSpace(config.ApiBaseUrl) ? DefaultBaseUrl : config.ApiBaseUrl.TrimEnd('/');
            var purchaseUri = new Uri(endpoint.EndsWith("/api", StringComparison.OrdinalIgnoreCase)
                ? $"{endpoint}/tariffs/{Uri.EscapeDataString(tariffId)}/purchase"
                : $"{endpoint}/api/tariffs/{Uri.EscapeDataString(tariffId)}/purchase");
            var payload = JsonSerializer.Serialize(new { computer_id = computerId, session_id = sessionId });
            using var request = new HttpRequestMessage(HttpMethod.Post, purchaseUri)
            {
                Content = new StringContent(payload, Encoding.UTF8, "application/json")
            };
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
            using var response = await _http.SendAsync(request, cancellationToken).ConfigureAwait(false);
            var responseBody = await response.Content.ReadAsStringAsync(cancellationToken).ConfigureAwait(false);
            if (!response.IsSuccessStatusCode)
                return new PurchaseResult { Success = false, Message = ExtractErrorMessage(responseBody) ?? "Не удалось купить тариф." };

            return JsonSerializer.Deserialize<PurchaseResult>(responseBody)
                ?? new PurchaseResult { Success = false, Message = "Сервер вернул неожиданный ответ." };
        }

        public Task<bool> EndSessionAsync(string sessionId, CancellationToken cancellationToken)
        {
            return Task.FromResult(true);
        }

        private static AuthResult ParseAuthResponse(string payload)
        {
            using var document = JsonDocument.Parse(payload);
            var root = document.RootElement;

            if (root.ValueKind != JsonValueKind.Object)
                return new AuthResult { Success = false, Message = "Сервер Zafiro вернул неожиданный ответ." };

            var success = TryGetBoolean(root, "success");
            if (success is false)
                return new AuthResult { Success = false, Message = ExtractErrorMessage(payload) ?? "Не удалось выполнить вход." };

            var userName = ExtractString(root, "user_name", "userName", "name", "full_name", "display_name")
                ?? ExtractNestedString(root, "user", "name", "full_name", "display_name")
                ?? "Пользователь";

            var token = ExtractString(root, "token", "access_token", "accessToken")
                ?? ExtractNestedString(root, "data", "token", "access_token", "accessToken");

            var sessionId = ExtractString(root, "session_id", "sessionId", "session", "id", "user_id")
                ?? ExtractNestedString(root, "data", "session_id", "sessionId", "id", "user_id")
                ?? token;

            var expiresAt = ExtractDate(root, "expires_at", "expiresAt", "expires_at_utc", "token_expires_at")
                ?? ExtractDate(root, "expires_in", "token_ttl");

            return new AuthResult
            {
                Success = true,
                UserName = userName,
                SessionId = sessionId,
                Token = token,
                ExpiresAt = expiresAt
            };
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

        private static string? ExtractNestedString(JsonElement root, string parentName, params string[] names)
        {
            if (!root.TryGetProperty(parentName, out var parent) || parent.ValueKind != JsonValueKind.Object)
                return null;

            return ExtractString(parent, names);
        }

        private static bool? TryGetBoolean(JsonElement root, string name)
        {
            if (root.TryGetProperty(name, out var value))
            {
                if (value.ValueKind == JsonValueKind.True) return true;
                if (value.ValueKind == JsonValueKind.False) return false;
                if (value.ValueKind == JsonValueKind.String && bool.TryParse(value.GetString(), out var parsed))
                    return parsed;
            }
            return null;
        }

        private static DateTime? ExtractDate(JsonElement root, params string[] names)
        {
            foreach (var name in names)
            {
                if (root.TryGetProperty(name, out var value))
                {
                    if (value.ValueKind == JsonValueKind.String && DateTimeOffset.TryParse(value.GetString(), CultureInfo.InvariantCulture, DateTimeStyles.AssumeUniversal, out var parsed))
                        return parsed.UtcDateTime;

                    if (value.ValueKind == JsonValueKind.Number && value.TryGetInt64(out var seconds) && seconds > 0)
                        return DateTime.UtcNow.AddSeconds(seconds);
                }
            }

            return null;
        }
    }
}
