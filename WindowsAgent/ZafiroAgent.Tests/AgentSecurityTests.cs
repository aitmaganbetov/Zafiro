using System.Net;
using System.Text.Json;
using ZafiroAgent.Configuration;
using ZafiroAgent.Security;
using ZafiroAgent.Services;

namespace ZafiroAgent.Tests;

public sealed class AgentSecurityTests : IDisposable
{
    private const string UserToken = "user-token-123";
    private const string DeviceToken = "zag_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA";
    private readonly string _dir = Path.Combine(Path.GetTempPath(), "zafiro-sec-" + Guid.NewGuid().ToString("N"));
    private readonly SecureStorageService _secure = new();

    public void Dispose()
    {
        if (Directory.Exists(_dir)) Directory.Delete(_dir, recursive: true);
    }

    private ConfigurationService Config(Action<AgentConfig>? change = null)
    {
        var config = new ConfigurationService(Path.Combine(_dir, "agent.config.json"));
        var cfg = new AgentConfig
        {
            ClubId = "5",
            ClubName = "Asia",
            ApiBaseUrl = "https://zafiro.kz/api/",
            LoginEndpoint = "https://zafiro.kz/api/agent/user/login",
            ProtectedClubKey = _secure.Protect("club-key"),
            ProtectedApiToken = _secure.Protect(UserToken),
            TokenExpiresAtUtc = DateTime.UtcNow.AddDays(1),
            ComputerId = "45"
        };
        change?.Invoke(cfg);
        config.Save(cfg);
        return config;
    }

    [Fact]
    public async Task SignOut_RevokesTheTokenOnTheServer_AndAlwaysClearsItLocally()
    {
        var handler = new StubHandler((_, _) => StubHandler.Json(HttpStatusCode.OK, "{}"));
        var config = Config();
        var setup = new ClubSetupService(new ZafiroApiClient(new HttpClient(handler)), config, _secure, "PC-07");

        await setup.SignOutAsync(TimeSpan.FromSeconds(2));

        var request = Assert.Single(handler.Requests);
        Assert.Equal("https://zafiro.kz/api/auth/logout", request.Url.AbsoluteUri);
        Assert.Equal("Bearer " + UserToken, request.Authorization);
        Assert.Equal(string.Empty, config.Load().ProtectedApiToken);

        var offline = new ClubSetupService(new ZafiroApiClient(new HttpClient(new StubHandler((_, _) => throw new HttpRequestException("offline")))), Config(), _secure, "PC-07");
        await offline.SignOutAsync(TimeSpan.FromSeconds(2));
        Assert.Equal(string.Empty, new ConfigurationService(Path.Combine(_dir, "agent.config.json")).Load().ProtectedApiToken);
    }

    [Theory]
    [InlineData(true)]
    [InlineData(false)]
    public async Task ClaimComputer_StoresTheDeviceTokenOnlyForStaff(bool staff)
    {
        var body = staff
            ? """{"computer_id":45,"number":"7","name":"PC-07","zone":{"id":3,"name":"VIP"},"agent_token":"DEVICE"}""".Replace("DEVICE", DeviceToken)
            : """{"computer_id":45,"number":"7","name":"PC-07","zone":{"id":3,"name":"VIP"}}""";
        var handler = new StubHandler((_, _) => StubHandler.Json(HttpStatusCode.OK, body));
        var config = Config();
        var setup = new ClubSetupService(new ZafiroApiClient(new HttpClient(handler)), config, _secure, "PC-07");

        var result = await setup.ClaimComputerAsync(setup.LoadSession().Value!, "uuid-1", CancellationToken.None);

        Assert.True(result.Success);
        Assert.Equal(staff ? DeviceToken : null, result.Value!.AgentToken);
        Assert.Equal(staff ? DeviceToken : string.Empty, _secure.Unprotect(config.Load().ProtectedDeviceToken));
        Assert.Equal("7", config.Load().ComputerNumber);
        Assert.DoesNotContain(DeviceToken, File.ReadAllText(Path.Combine(_dir, "agent.config.json")));
    }

    [Theory]
    [InlineData(true)]
    [InlineData(false)]
    public async Task ClaimComputer_AsksForTheDeviceTokenOnlyWhenConnectingThePc(bool claim)
    {
        var handler = new StubHandler((_, _) => StubHandler.Json(HttpStatusCode.OK, """{"computer_id":45,"number":"7","name":"PC-07","zone":{"id":3,"name":"VIP"}}"""));
        var setup = new ClubSetupService(new ZafiroApiClient(new HttpClient(handler)), Config(), _secure, "PC-07");

        await setup.ClaimComputerAsync(setup.LoadSession().Value!, "uuid-1", CancellationToken.None, claim);

        Assert.Contains($"\"claim\":{(claim ? "true" : "false")}", Assert.Single(handler.Requests).Body);
    }

    [Fact]
    public async Task TariffPurchase_ProvesItComesFromThisPc()
    {
        var handler = new StubHandler((_, _) => StubHandler.Json(HttpStatusCode.OK, """{"success":true,"session_id":"s1"}"""));
        var config = Config(cfg => cfg.ProtectedDeviceToken = _secure.Protect(DeviceToken));
        var service = new HttpApiService(new HttpClient(handler), config: config, secure: _secure);

        await service.PurchaseTariffAsync("7", CancellationToken.None);

        Assert.Equal(DeviceToken, Assert.Single(handler.Requests).AgentToken);
    }

    [Fact]
    public async Task ExitPin_NamesTheSignedInUserForTheActivityLog()
    {
        var handler = new StubHandler((_, _) => StubHandler.Json(HttpStatusCode.OK, """{"valid":true}"""));
        var api = new ZafiroApiClient(new HttpClient(handler));

        Assert.True((await api.VerifyExitPinAsync(DeviceToken, "4821", CancellationToken.None, UserToken)).Success);

        var request = Assert.Single(handler.Requests);
        Assert.Equal(DeviceToken, request.AgentToken);
        Assert.Equal("Bearer " + UserToken, request.Authorization);
        using var json = JsonDocument.Parse(request.Body!);
        Assert.Equal("4821", json.RootElement.GetProperty("pin").GetString());
    }
}
