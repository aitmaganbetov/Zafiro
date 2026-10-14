using System.Net;
using System.Text.Json;
using ZafiroAgent.Configuration;
using ZafiroAgent.Security;
using ZafiroAgent.Services;

namespace ZafiroAgent.Tests;

public sealed class HttpApiServiceTests
{
    [Theory]
    [InlineData("7001234567", "+77001234567")]
    [InlineData("+7 700 123-45-67", "+77001234567")]
    [InlineData("8 (700) 123-45-67", "+77001234567")]
    public void NormalizeKazakhstanNumber_FormatsTenDigitLocalAndElevenDigitFullNumbers(string input, string expected)
    {
        Assert.Equal(expected, PhoneNumberFormatter.NormalizeKazakhstanNumber(input));
    }

    [Theory]
    [InlineData("")]
    [InlineData("700123456")]
    [InlineData("770012345678")]
    public void NormalizeKazakhstanNumber_RejectsInvalidDigitCount(string input)
    {
        Assert.Null(PhoneNumberFormatter.NormalizeKazakhstanNumber(input));
    }

    [Fact]
    public async Task AuthenticateAsync_PostsToAgentLoginEndpoint_AndParsesUserData()
    {
        var handler = new StubHandler((request, body) =>
        {
            Assert.Equal(HttpMethod.Post, request.Method);
            Assert.Equal("https://zafiro.kz/api/agent/user/login", request.RequestUri!.AbsoluteUri);
            using var json = JsonDocument.Parse(body!);
            Assert.Equal("+77001234567", json.RootElement.GetProperty("phone").GetString());
            Assert.Equal("p@ssw0rd", json.RootElement.GetProperty("password").GetString());
            return StubHandler.Json(HttpStatusCode.OK, """
                {
                  "success": true,
                  "session_id": "session-42",
                  "user": { "name": "Иван Иванов" },
                  "expires_at": "2026-10-13T18:30:00Z"
                }
                """);
        });

        var service = new HttpApiService(new HttpClient(handler));

        var result = await service.AuthenticateAsync("+77001234567", "p@ssw0rd", CancellationToken.None);

        Assert.True(result.Success);
        Assert.Equal("session-42", result.SessionId);
        Assert.Equal("Иван Иванов", result.UserName);
        Assert.Equal(new DateTime(2026, 10, 13, 18, 30, 0, DateTimeKind.Utc), result.ExpiresAt);
    }

    [Fact]
    public async Task AuthenticateAsync_MapsUnauthorizedErrorWithoutLeakingSecrets()
    {
        var handler = new StubHandler((_, _) => StubHandler.Json(HttpStatusCode.Unauthorized, "{\"message\":\"Неверный логин или пароль\"}"));
        var service = new HttpApiService(new HttpClient(handler));

        var result = await service.AuthenticateAsync("+77001234567", "secret-password", CancellationToken.None);

        Assert.False(result.Success);
        Assert.Equal("Неверный логин или пароль", result.Message);
        Assert.DoesNotContain("secret-password", result.Message);
    }

    [Fact]
    public async Task GetSessionInfoAsync_UsesBearerTokenAndParsesProfileBalance()
    {
        var token = "jwt-token-123";
        var handler = new StubHandler((request, _) =>
        {
            Assert.Equal(HttpMethod.Get, request.Method);
            Assert.Equal("https://zafiro.kz/api/me", request.RequestUri!.AbsoluteUri);
            Assert.Equal("Bearer " + token, request.Headers.Authorization?.ToString());
            return StubHandler.Json(HttpStatusCode.OK, """
                {
                  "user_id": 42,
                  "name": "Имя пользователя",
                  "phone": "+79001234567",
                  "club_id": 2,
                  "balance": 150.5,
                  "bonus_balance": 20.0,
                  "currency": "KZT"
                }
                """);
        });

        var path = Path.Combine(Path.GetTempPath(), "zafiro-balance-tests-" + Guid.NewGuid().ToString("N"), "agent.config.json");
        var config = new ConfigurationService(path);
        var secure = new SecureStorageService();
        config.Save(new AgentConfig { ProtectedApiToken = secure.Protect(token), ApiBaseUrl = "https://zafiro.kz/api" });

        var service = new HttpApiService(new HttpClient(handler), "https://zafiro.kz", config, secure);
        var session = await service.GetSessionInfoAsync("ignored", CancellationToken.None);

        Assert.Equal("Имя пользователя", session.UserName);
        Assert.Equal(150.5m, session.Balance);
        Assert.Equal(20.0m, session.BonusBalance);
        Assert.Equal("42", session.SessionId);
    }

    [Fact]
    public async Task GetSessionInfoAsync_ReportsUnauthorizedInsteadOfReturningZeroBalance()
    {
        const string token = "expired-token";
        var handler = new StubHandler((request, _) =>
        {
            Assert.Equal("Bearer " + token, request.Headers.Authorization?.ToString());
            return StubHandler.Json(HttpStatusCode.Unauthorized, "{}");
        });
        var path = Path.Combine(Path.GetTempPath(), "zafiro-profile-error-tests-" + Guid.NewGuid().ToString("N"), "agent.config.json");
        var config = new ConfigurationService(path);
        var secure = new SecureStorageService();
        config.Save(new AgentConfig { ProtectedApiToken = secure.Protect(token), ApiBaseUrl = "https://zafiro.kz/api" });
        var service = new HttpApiService(new HttpClient(handler), config: config, secure: secure);

        var error = await Assert.ThrowsAsync<HttpRequestException>(() => service.GetSessionInfoAsync("session", CancellationToken.None));

        Assert.Equal(HttpStatusCode.Unauthorized, error.StatusCode);
    }

        [Fact]
        public async Task GetProductsAsync_LoadsApiProductEnvelope()
        {
            const string token = "products-token-123";
                var handler = new StubHandler((request, _) =>
                {
                        Assert.Equal(HttpMethod.Get, request.Method);
                        Assert.Equal("https://zafiro.kz/api/products", request.RequestUri!.AbsoluteUri);
                Assert.Equal("Bearer " + token, request.Headers.Authorization?.ToString());
                        return StubHandler.Json(HttpStatusCode.OK, """
                                {
                                    "data": [
                                        {
                                            "id": 1,
                                            "sku": "DRK-001",
                                            "name": "Coca-Cola 0,5 л",
                                            "category": "Напитки",
                                            "price": 650,
                                            "currency": "KZT",
                                            "quantity": 48,
                                            "in_stock": true,
                                            "image_url": "https://zafiro.kz/images/product-placeholder.svg"
                                        }
                                    ]
                                }
                                """);
                });
                var path = Path.Combine(Path.GetTempPath(), "zafiro-products-tests-" + Guid.NewGuid().ToString("N"), "agent.config.json");
                var config = new ConfigurationService(path);
                var secure = new SecureStorageService();
                config.Save(new AgentConfig { ProtectedApiToken = secure.Protect(token), ApiBaseUrl = "https://zafiro.kz/api" });
                var service = new HttpApiService(new HttpClient(handler), config: config, secure: secure);

                var products = await service.GetProductsAsync(CancellationToken.None);

                var product = Assert.Single(products);
                Assert.Equal(1, product.Id);
                Assert.Equal("DRK-001", product.Sku);
                Assert.Equal("Coca-Cola 0,5 л", product.Name);
                Assert.Equal(650m, product.Price);
                Assert.Equal(48, product.Quantity);
                Assert.True(product.InStock);
                Assert.Equal("https://zafiro.kz/images/product-placeholder.svg", product.ImageUrl);
        }

    [Fact]
    public async Task GetTariffsAsync_LoadsAvailableTariffsWithCurrency()
    {
        const string token = "tariff-token";
        var handler = new StubHandler((request, _) =>
        {
            Assert.Equal(HttpMethod.Get, request.Method);
            Assert.Equal("https://zafiro.kz/api/tariffs", request.RequestUri!.AbsoluteUri);
            Assert.Equal("Bearer " + token, request.Headers.Authorization?.ToString());
            return StubHandler.Json(HttpStatusCode.OK, """
                {"data":[{"id":"7","name":"Ночной","description":"Игры","minutes":480,"price":"3150.00","currency":"KZT"}]}
                """);
        });
        var path = Path.Combine(Path.GetTempPath(), "zafiro-tariffs-tests-" + Guid.NewGuid().ToString("N"), "agent.config.json");
        var config = new ConfigurationService(path);
        var secure = new SecureStorageService();
        config.Save(new AgentConfig { ProtectedApiToken = secure.Protect(token), ApiBaseUrl = "https://zafiro.kz/api" });
        var service = new HttpApiService(new HttpClient(handler), config: config, secure: secure);

        var tariff = Assert.Single(await service.GetTariffsAsync(CancellationToken.None));

        Assert.Equal("7", tariff.Id);
        Assert.Equal("Ночной", tariff.Name);
        Assert.Equal(480, tariff.Minutes);
        Assert.Equal(3150m, tariff.Price);
        Assert.Equal("KZT", tariff.Currency);
    }

    [Fact]
    public async Task PurchaseTariffAsync_SendsComputerIdOnly_AndParsesExtendedSession()
    {
        const string token = "purchase-token";
        var handler = new StubHandler((request, body) =>
        {
            Assert.Equal(HttpMethod.Post, request.Method);
            Assert.Equal("https://zafiro.kz/api/tariffs/7/purchase", request.RequestUri!.AbsoluteUri);
            Assert.Equal("Bearer " + token, request.Headers.Authorization?.ToString());
            using var json = JsonDocument.Parse(body!);
            Assert.Equal(45, json.RootElement.GetProperty("computer_id").GetInt64());
            Assert.False(json.RootElement.TryGetProperty("session_id", out _));
            return StubHandler.Json(HttpStatusCode.OK, """
                {"success":true,"message":"Тариф подключён.","session_id":"sess-777","new_balance":"0.00","new_bonus":"100.00","minutes_added":30,"session_ends_at":"2026-10-14T12:40:00+00:00"}
                """);
        });
        var path = Path.Combine(Path.GetTempPath(), "zafiro-purchase-tests-" + Guid.NewGuid().ToString("N"), "agent.config.json");
        var config = new ConfigurationService(path);
        var secure = new SecureStorageService();
        config.Save(new AgentConfig { ComputerId = "45", ProtectedApiToken = secure.Protect(token), ApiBaseUrl = "https://zafiro.kz/api" });
        var service = new HttpApiService(new HttpClient(handler), config: config, secure: secure);

        var result = await service.PurchaseTariffAsync("7", CancellationToken.None);

        Assert.True(result.Success);
        Assert.Equal("sess-777", result.SessionId);
        Assert.Equal(0m, result.NewBalance);
        Assert.Equal(100m, result.NewBonus);
        Assert.Equal(30, result.MinutesAdded);
        Assert.Equal(DateTimeOffset.Parse("2026-10-14T12:40:00+00:00"), result.SessionEndsAt);
    }

    [Fact]
    public async Task EndSessionAsync_AsksServerToEndThePaidSession()
    {
        const string token = "end-token";
        var handler = new StubHandler((request, body) =>
        {
            Assert.Equal(HttpMethod.Post, request.Method);
            Assert.Equal("https://zafiro.kz/api/club-computers/45/session/end", request.RequestUri!.AbsoluteUri);
            Assert.Equal("Bearer " + token, request.Headers.Authorization?.ToString());
            using var json = JsonDocument.Parse(body!);
            Assert.Equal("sess-777", json.RootElement.GetProperty("session_id").GetString());
            return StubHandler.Json(HttpStatusCode.OK, """{"success":true,"session":null}""");
        });
        var path = Path.Combine(Path.GetTempPath(), "zafiro-end-tests-" + Guid.NewGuid().ToString("N"), "agent.config.json");
        var config = new ConfigurationService(path);
        var secure = new SecureStorageService();
        config.Save(new AgentConfig { ComputerId = "45", ProtectedApiToken = secure.Protect(token), ApiBaseUrl = "https://zafiro.kz/api" });
        var service = new HttpApiService(new HttpClient(handler), config: config, secure: secure);

        Assert.True(await service.EndSessionAsync("sess-777", CancellationToken.None));
        Assert.Single(handler.Requests);
    }

    [Fact]
    public async Task Requests_NeverGoToAHostFromTamperedSettings()
    {
        var handler = new StubHandler((request, _) =>
        {
            Assert.Equal("zafiro.kz", request.RequestUri!.Host);
            return StubHandler.Json(HttpStatusCode.OK, """{"data":[]}""");
        });
        var path = Path.Combine(Path.GetTempPath(), "zafiro-host-tests-" + Guid.NewGuid().ToString("N"), "agent.config.json");
        var config = new ConfigurationService(path);
        var secure = new SecureStorageService();
        config.Save(new AgentConfig { ProtectedApiToken = secure.Protect("t"), ApiBaseUrl = "https://evil.example.com/api" });
        var service = new HttpApiService(new HttpClient(handler), config: config, secure: secure);

        await service.GetTariffsAsync(CancellationToken.None);

        Assert.Equal("https://zafiro.kz/api/tariffs", Assert.Single(handler.Requests).Url.AbsoluteUri);
    }
}
