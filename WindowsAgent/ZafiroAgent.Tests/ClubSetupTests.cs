using System.Net;
using System.Text.Json;
using ZafiroAgent.Configuration;
using ZafiroAgent.Models;
using ZafiroAgent.Security;
using ZafiroAgent.Services;

namespace ZafiroAgent.Tests;

public sealed class ClubSetupTests : IDisposable
{
    private const string Key = "install-key-SECRET-123";
    private const string Phone = "+77001234567";
    private const string Password = "p@ssw0rd-SECRET";
    private const string Token = "api-token-SECRET-abc";

    private static readonly DateTime Now = new(2026, 10, 13, 12, 0, 0, DateTimeKind.Utc);

    private const string ResolveOk = """
        {"club_id":123,"club_name":"Asia","api_base_url":"https://zafiro.kz/api","login_endpoint":"https://zafiro.kz/api/auth/login"}
        """;

    private readonly string _dir = Path.Combine(Path.GetTempPath(), "zafiro-tests-" + Guid.NewGuid().ToString("N"));
    private readonly string _configPath;
    private readonly SecureStorageService _secure = new();

    public ClubSetupTests()
    {
        _configPath = Path.Combine(_dir, "agent.config.json");
    }

    public void Dispose()
    {
        if (Directory.Exists(_dir)) Directory.Delete(_dir, recursive: true);
    }

    private static string LoginOk(long clubId = 123, string? extra = null) =>
        $$"""{"token":"{{Token}}","club_id":{{clubId}},"club_name":"Asia"{{extra}}}""";

    private (ClubSetupService Setup, StubHandler Handler, ZafiroApiClient Api, ConfigurationService Config) Create(Func<HttpRequestMessage, string?, HttpResponseMessage> respond)
    {
        var handler = new StubHandler(respond);
        var api = new ZafiroApiClient(new HttpClient(handler), () => Now);
        var config = new ConfigurationService(_configPath);
        var setup = new ClubSetupService(api, config, _secure, "TEST-PC", () => Now);
        return (Setup: setup, Handler: handler, Api: api, Config: config);
    }

    private static HttpResponseMessage Route(HttpRequestMessage request, string resolve, string login) =>
        request.RequestUri!.AbsolutePath.EndsWith("/clubs/resolve")
            ? StubHandler.Json(resolve[0] == '{' ? HttpStatusCode.OK : HttpStatusCode.Unauthorized, resolve[0] == '{' ? resolve : "{}")
            : StubHandler.Json(HttpStatusCode.OK, login);

    [Fact]
    public async Task Setup_ResolvesClubAndLogsIn_SendsExpectedRequests()
    {
        var (setup, handler, _, config) = Create((r, _) => Route(r, ResolveOk, LoginOk()));

        var result = await setup.SetupAsync(Key, Phone, Password, CancellationToken.None);

        Assert.True(result.Success);
        Assert.Equal(2, handler.Requests.Count);

        var resolve = handler.Requests[0];
        Assert.Equal(HttpMethod.Post, resolve.Method);
        Assert.Equal("https://zafiro.kz/api/clubs/resolve", resolve.Url.AbsoluteUri);
        Assert.Null(resolve.Authorization);
        using (var body = JsonDocument.Parse(resolve.Body!))
            Assert.Equal(Key, body.RootElement.GetProperty("club_key").GetString());

        var login = handler.Requests[1];
        Assert.Equal("https://zafiro.kz/api/agent/user/login", login.Url.AbsoluteUri);
        using (var body = JsonDocument.Parse(login.Body!))
        {
            var root = body.RootElement;
            Assert.Equal(Phone, root.GetProperty("phone").GetString());
            Assert.Equal(Password, root.GetProperty("password").GetString());
            Assert.Equal("TEST-PC", root.GetProperty("device_name").GetString());
            Assert.Equal(Key, root.GetProperty("club_key").GetString());
            Assert.Equal(123, root.GetProperty("club_id").GetInt64());
        }

        var saved = config.Load();
        Assert.Equal("123", saved.ClubId);
        Assert.Equal("Asia", saved.ClubName);
        Assert.Equal("https://zafiro.kz/api", saved.ApiBaseUrl.TrimEnd('/'));
        Assert.Equal("https://zafiro.kz/api/auth/login", saved.LoginEndpoint);
        Assert.Equal(Now.AddDays(30), saved.TokenExpiresAtUtc);
    }

    [Fact]
    public async Task Setup_FallsBackToResolvedLoginEndpointWhenAgentRouteIsNotFound()
    {
        var (setup, handler, _, _) = Create((request, _) => request.RequestUri!.AbsolutePath switch
        {
            "/api/clubs/resolve" => StubHandler.Json(HttpStatusCode.OK, ResolveOk),
            "/api/agent/user/login" => StubHandler.Json(HttpStatusCode.NotFound, "{}"),
            _ => StubHandler.Json(HttpStatusCode.OK, LoginOk())
        });

        var result = await setup.SetupAsync(Key, Phone, Password, CancellationToken.None);

        Assert.True(result.Success);
        Assert.Equal(3, handler.Requests.Count);
        Assert.Equal("https://zafiro.kz/api/agent/user/login", handler.Requests[1].Url.AbsoluteUri);
        Assert.Equal("https://zafiro.kz/api/auth/login", handler.Requests[2].Url.AbsoluteUri);
    }

    [Fact]
    public async Task Setup_UsesTokenExpiryFromServerWhenProvided()
    {
        var (setup, _, _, config) = Create((r, _) => Route(r, ResolveOk, LoginOk(extra: ",\"expires_in\":3600")));

        var result = await setup.SetupAsync(Key, Phone, Password, CancellationToken.None);

        Assert.True(result.Success);
        Assert.Equal(Now.AddHours(1), config.Load().TokenExpiresAtUtc);
    }

    [Fact]
    public async Task Resolve_WithInvalidKey_Returns401MessageAndSavesNothing()
    {
        var (setup, handler, _, _) = Create((_, _) => StubHandler.Json(HttpStatusCode.Unauthorized, "{}"));

        var result = await setup.SetupAsync(Key, Phone, Password, CancellationToken.None);

        Assert.False(result.Success);
        Assert.Equal(ApiFailure.InvalidKey, result.Failure);
        Assert.Single(handler.Requests);
        Assert.False(setup.IsClubConfigured);
        Assert.False(File.Exists(_configPath));
    }

    [Fact]
    public async Task Login_WithClubIdMismatch_DoesNotStartAgent()
    {
        var (setup, _, _, _) = Create((r, _) => Route(r, ResolveOk, LoginOk(clubId: 999)));

        var result = await setup.SetupAsync(Key, Phone, Password, CancellationToken.None);

        Assert.False(result.Success);
        Assert.Equal(ApiFailure.ClubMismatch, result.Failure);
        Assert.False(setup.IsClubConfigured);
        Assert.Equal(ApiFailure.NotConfigured, setup.LoadSession().Failure);
    }

    [Theory]
    [InlineData(HttpStatusCode.Unauthorized, ApiFailure.InvalidKey)]
    [InlineData(HttpStatusCode.PaymentRequired, ApiFailure.SubscriptionInactive)]
    [InlineData(HttpStatusCode.Forbidden, ApiFailure.Forbidden)]
    public async Task Resolve_ErrorStatuses_AreMappedAndNotRetried(HttpStatusCode status, ApiFailure expected)
    {
        var (setup, handler, _, _) = Create((_, _) => StubHandler.Json(status, "{}"));

        var result = await setup.SetupAsync(Key, Phone, Password, CancellationToken.None);

        Assert.Equal(expected, result.Failure);
        Assert.Single(handler.Requests);
        Assert.NotEmpty(result.Message);
    }

    [Theory]
    [InlineData(HttpStatusCode.Unauthorized, ApiFailure.InvalidCredentials)]
    [InlineData(HttpStatusCode.PaymentRequired, ApiFailure.SubscriptionInactive)]
    [InlineData(HttpStatusCode.Forbidden, ApiFailure.Forbidden)]
    public async Task Login_ErrorStatuses_AreMappedAndNotRetried(HttpStatusCode status, ApiFailure expected)
    {
        var (setup, handler, _, _) = Create((r, _) =>
            r.RequestUri!.AbsolutePath.EndsWith("/clubs/resolve")
                ? StubHandler.Json(HttpStatusCode.OK, ResolveOk)
                : StubHandler.Json(status, "{}"));

        var result = await setup.SetupAsync(Key, Phone, Password, CancellationToken.None);

        Assert.Equal(expected, result.Failure);
        Assert.Equal(2, handler.Requests.Count);
        Assert.False(setup.IsClubConfigured);
        if (expected == ApiFailure.InvalidCredentials)
            Assert.Equal("Неверный номер или пароль.", result.Message);
    }

    [Theory]
    [InlineData("http://zafiro.kz/api")]
    [InlineData("https://evil.example/api")]
    [InlineData("https://zafiro.kz.evil.example/api")]
    [InlineData("https://user:pass@zafiro.kz/api")]
    [InlineData("https://zafiro.kz:8443/api")]
    public async Task Resolve_RejectsNonHttpsOrForeignApiBases_BeforeLogin(string apiBase)
    {
        var resolve = $$"""{"club_id":123,"club_name":"Asia","api_base_url":"{{apiBase}}","login_endpoint":"https://zafiro.kz/api/auth/login"}""";
        var (setup, handler, _, _) = Create((_, _) => StubHandler.Json(HttpStatusCode.OK, resolve));

        var result = await setup.SetupAsync(Key, Phone, Password, CancellationToken.None);

        Assert.Equal(ApiFailure.InvalidEndpoint, result.Failure);
        Assert.Single(handler.Requests);
        Assert.False(setup.IsClubConfigured);
    }

    [Fact]
    public async Task AuthorizedRequest_UsesBaseUrlAndBearerToken()
    {
        var (setup, handler, api, _) = Create((r, _) => Route(r, ResolveOk, LoginOk()));
        var session = (await setup.SetupAsync(Key, Phone, Password, CancellationToken.None)).Value!;
        handler.Requests.Clear();

        using var ok = new StubHandler((_, _) => StubHandler.Json(HttpStatusCode.OK, "{}"));
        var client = new ZafiroApiClient(new HttpClient(ok));
        using var response = await client.SendAuthorizedAsync(session, HttpMethod.Post, "club-computers/7/heartbeat", null, CancellationToken.None);

        Assert.True(response.IsSuccessStatusCode);
        var sent = Assert.Single(ok.Requests);
        Assert.Equal("https://zafiro.kz/api/club-computers/7/heartbeat", sent.Url.AbsoluteUri);
        Assert.Equal("Bearer " + Token, sent.Authorization);
        Assert.Empty(handler.Requests);
        _ = api;
    }

    [Theory]
    [InlineData("../admin")]
    [InlineData("https://evil.example/steal")]
    [InlineData("//evil.example/steal")]
    public async Task AuthorizedRequest_RejectsPathsOutsideApiBase(string path)
    {
        var session = new ClubSession(123, "Asia", new Uri("https://zafiro.kz/api"), Token, Now.AddDays(1));
        var handler = new StubHandler((_, _) => StubHandler.Json(HttpStatusCode.OK, "{}"));
        var client = new ZafiroApiClient(new HttpClient(handler));

        var ex = await Assert.ThrowsAsync<ZafiroApiException>(() =>
            client.SendAuthorizedAsync(session, HttpMethod.Get, path, null, CancellationToken.None));

        Assert.Equal(ApiFailure.InvalidEndpoint, ex.Failure);
        Assert.Empty(handler.Requests);
    }

    [Theory]
    [InlineData(HttpStatusCode.Unauthorized, ApiFailure.TokenExpired)]
    [InlineData(HttpStatusCode.PaymentRequired, ApiFailure.SubscriptionInactive)]
    [InlineData(HttpStatusCode.Forbidden, ApiFailure.Forbidden)]
    public async Task AuthorizedRequest_ErrorStatuses_Throw(HttpStatusCode status, ApiFailure expected)
    {
        var session = new ClubSession(123, "Asia", new Uri("https://zafiro.kz/api"), Token, Now.AddDays(1));
        var handler = new StubHandler((_, _) => StubHandler.Json(status, "{}"));
        var client = new ZafiroApiClient(new HttpClient(handler));

        var ex = await Assert.ThrowsAsync<ZafiroApiException>(() =>
            client.SendAuthorizedAsync(session, HttpMethod.Get, "me", null, CancellationToken.None));

        Assert.Equal(expected, ex.Failure);
        Assert.DoesNotContain(Token, ex.Message);
    }

    [Fact]
    public async Task Config_StoresSecretsOnlyProtected()
    {
        var (setup, _, _, config) = Create((r, _) => Route(r, ResolveOk, LoginOk()));

        await setup.SetupAsync(Key, Phone, Password, CancellationToken.None);

        var raw = File.ReadAllText(_configPath);
        Assert.DoesNotContain(Key, raw);
        Assert.DoesNotContain(Token, raw);
        Assert.DoesNotContain(Password, raw);

        var saved = config.Load();
        Assert.NotEmpty(saved.ProtectedClubKey);
        Assert.NotEmpty(saved.ProtectedApiToken);
        Assert.Equal(Key, _secure.Unprotect(saved.ProtectedClubKey));
        Assert.Equal(Token, _secure.Unprotect(saved.ProtectedApiToken));
    }

    [Fact]
    public async Task FailedAttempts_NeverLeakSecretsInMessages()
    {
        var statuses = new[] { HttpStatusCode.Unauthorized, HttpStatusCode.PaymentRequired, HttpStatusCode.Forbidden, HttpStatusCode.InternalServerError };
        foreach (var status in statuses)
        {
            var (setup, _, _, _) = Create((_, _) => StubHandler.Json(status, $"{{\"echo\":\"{Key} {Password} {Token}\"}}"));
            var result = await setup.SetupAsync(Key, Phone, Password, CancellationToken.None);

            Assert.DoesNotContain(Key, result.Message);
            Assert.DoesNotContain(Password, result.Message);
            Assert.DoesNotContain(Token, result.Message);
        }
    }

    [Fact]
    public async Task Setup_WhenClubAlreadyConfigured_DoesNotSwitchClub()
    {
        var (setup, handler, _, config) = Create((r, _) => Route(r, ResolveOk, LoginOk()));
        await setup.SetupAsync(Key, Phone, Password, CancellationToken.None);
        handler.Requests.Clear();

        var again = await setup.SetupAsync("other-key", Phone, Password, CancellationToken.None);

        Assert.Equal(ApiFailure.ClubAlreadyConfigured, again.Failure);
        Assert.Empty(handler.Requests);
        Assert.Equal("123", config.Load().ClubId);
    }

    [Fact]
    public async Task LoadSession_ReturnsStoredTokenUntilExpiry_ThenAsksForRelogin()
    {
        var (setup, _, _, config) = Create((r, _) => Route(r, ResolveOk, LoginOk()));
        await setup.SetupAsync(Key, Phone, Password, CancellationToken.None);

        var valid = setup.LoadSession();
        Assert.True(valid.Success);
        Assert.Equal(Token, valid.Value!.Token);

        var expired = config.Load();
        expired.TokenExpiresAtUtc = Now.AddMinutes(-1);
        config.Save(expired);

        Assert.Equal(ApiFailure.TokenExpired, setup.LoadSession().Failure);
    }

    [Fact]
    public async Task Relogin_ForSavedClub_ReusesStoredKeyAndClubId()
    {
        var (setup, handler, _, config) = Create((r, _) => Route(r, ResolveOk, LoginOk()));
        await setup.SetupAsync(Key, Phone, Password, CancellationToken.None);
        var stale = config.Load();
        stale.TokenExpiresAtUtc = Now.AddDays(-1);
        config.Save(stale);
        handler.Requests.Clear();

        var result = await setup.ReloginAsync(Phone, Password, CancellationToken.None);

        Assert.True(result.Success);
        Assert.Equal(2, handler.Requests.Count);
        using var body = JsonDocument.Parse(handler.Requests[1].Body!);
        Assert.Equal(Key, body.RootElement.GetProperty("club_key").GetString());
        Assert.Equal(123, body.RootElement.GetProperty("club_id").GetInt64());
        Assert.Equal(Now.AddDays(30), config.Load().TokenExpiresAtUtc);
    }

    [Fact]
    public async Task Relogin_WhenKeyRevoked_StopsWithoutLoginAttempt()
    {
        var first = Create((r, _) => Route(r, ResolveOk, LoginOk()));
        await first.Setup.SetupAsync(Key, Phone, Password, CancellationToken.None);

        var (setup, handler, _, _) = Create((_, _) => StubHandler.Json(HttpStatusCode.Unauthorized, "{}"));
        var result = await setup.ReloginAsync(Phone, Password, CancellationToken.None);

        Assert.Equal(ApiFailure.KeyRevoked, result.Failure);
        Assert.Single(handler.Requests);
        Assert.True(setup.IsClubConfigured);
    }

    [Fact]
    public async Task Relogin_WhenKeyNowBelongsToOtherClub_DoesNotSwitch()
    {
        var first = Create((r, _) => Route(r, ResolveOk, LoginOk()));
        await first.Setup.SetupAsync(Key, Phone, Password, CancellationToken.None);

        var otherClub = ResolveOk.Replace("\"club_id\":123", "\"club_id\":456");
        var (setup, handler, _, config) = Create((r, _) => Route(r, otherClub, LoginOk(456)));
        var result = await setup.ReloginAsync(Phone, Password, CancellationToken.None);

        Assert.Equal(ApiFailure.ClubMismatch, result.Failure);
        Assert.Single(handler.Requests);
        Assert.Equal("123", config.Load().ClubId);
    }

    [Fact]
    public async Task ResetClub_RemovesClubAndSecrets_ButKeepsOtherSettings()
    {
        var (setup, _, _, config) = Create((r, _) => Route(r, ResolveOk, LoginOk()));
        await setup.SetupAsync(Key, Phone, Password, CancellationToken.None);
        var cfg = config.Load();
        cfg.ComputerId = "7";
        config.Save(cfg);

        setup.ResetClub();

        var after = config.Load();
        Assert.False(setup.IsClubConfigured);
        Assert.Empty(after.ProtectedClubKey);
        Assert.Empty(after.ProtectedApiToken);
        Assert.Equal("7", after.ComputerId);
    }

    [Fact]
    public async Task Logout_RemovesTokenButKeepsClubAndKey_AndAllowsRelogin()
    {
        var (setup, handler, _, config) = Create((r, _) => Route(r, ResolveOk, LoginOk()));
        await setup.SetupAsync(Key, Phone, Password, CancellationToken.None);

        setup.Logout();

        var after = config.Load();
        Assert.Empty(after.ProtectedApiToken);
        Assert.Null(after.TokenExpiresAtUtc);
        Assert.Equal("123", after.ClubId);
        Assert.Equal(Key, _secure.Unprotect(after.ProtectedClubKey));
        Assert.True(setup.IsClubConfigured);
        Assert.Equal(ApiFailure.TokenExpired, setup.LoadSession().Failure);

        handler.Requests.Clear();
        var relogin = await setup.ReloginAsync(Phone, Password, CancellationToken.None);

        Assert.True(relogin.Success);
        Assert.True(setup.LoadSession().Success);
    }

    [Fact]
    public void ComputerIdAndZone_AreReadFromConfig_AndEmptyByDefault()
    {
        var (setup, _, _, config) = Create((_, _) => StubHandler.Json(HttpStatusCode.OK, "{}"));
        Assert.Empty(setup.ComputerId);
        Assert.Empty(setup.Zone);

        var cfg = config.Load();
        cfg.ComputerId = "12";
        cfg.ComputerNumber = "7";
        cfg.Zone = "VIP";
        config.Save(cfg);

        Assert.Equal("12", setup.ComputerId);
        Assert.Equal("7", setup.ComputerNumber);
        Assert.Equal("VIP", setup.Zone);
    }

    [Fact]
    public async Task NetworkFailure_ReturnsFriendlyMessage()
    {
        var (setup, handler, _, _) = Create((_, _) => throw new HttpRequestException("boom " + Key));

        var result = await setup.SetupAsync(Key, Phone, Password, CancellationToken.None);

        Assert.Equal(ApiFailure.Network, result.Failure);
        Assert.Single(handler.Requests);
        Assert.DoesNotContain(Key, result.Message);
    }

    [Fact]
    public void BindingFromTheImageOfAnotherPc_IsReset_OwnBindingIsKept()
    {
        var (setup, handler, _, config) = Create((_, _) => StubHandler.Json(HttpStatusCode.OK, "{}"));
        config.Update(c =>
        {
            c.ComputerId = "41";
            c.ComputerNumber = "1";
            c.Zone = "Hall";
            c.DeviceId = "MAC-AABBCC000001";
        });

        setup.ForgetAnotherPc("mac-aabbcc000001");
        Assert.Equal("41", config.Load().ComputerId);

        // ПК №2 загрузился из образа ПК №1: карточка ПК №1 ему не принадлежит.
        setup.ForgetAnotherPc("MAC-AABBCC000002");
        var cfg = config.Load();
        Assert.Equal(string.Empty, cfg.ComputerId);
        Assert.Equal(string.Empty, cfg.ComputerNumber);
        Assert.Equal(string.Empty, cfg.DeviceId);
        Assert.Empty(handler.Requests);
    }
}
