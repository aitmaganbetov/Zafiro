using System.Net;
using ZafiroAgent.Models;
using ZafiroAgent.Service;
using ZafiroAgent.Services;

// DeviceTokens.Vault — общая настройка процесса: тесты, которые её меняют, не должны идти параллельно с остальными.
[assembly: CollectionBehavior(DisableTestParallelization = true)]

namespace ZafiroAgent.Tests;

/// <summary>Служба хранит токен ПК: агент передаёт ей запросы с меткой, и токен не покидает службу.</summary>
public sealed class DeviceVaultTests : IDisposable
{
    private const string Token = "zag_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA";
    private const string NewToken = "zag_BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB";
    private const string UserToken = "user-token-123";

    private readonly string _dir = Path.Combine(Path.GetTempPath(), "zafiro-vault-" + Guid.NewGuid().ToString("N"));
    private readonly string _pipe = "ZafiroAgent.Test." + Guid.NewGuid().ToString("N");
    private readonly CancellationTokenSource _stop = new();

    public void Dispose()
    {
        _stop.Cancel();
        try
        {
            Directory.Delete(_dir, recursive: true);
        }
        catch (IOException)
        {
        }
    }

    [Theory]
    [InlineData("https://zafiro.kz/api/agent/heartbeat", true)]
    [InlineData("https://zafiro.kz/api/agent/exit-pin", true)]
    [InlineData("https://zafiro.kz/api/tariffs/12/purchase", true)]
    [InlineData("https://ZAFIRO.KZ/api/agent/heartbeat", true)]
    [InlineData("http://zafiro.kz/api/agent/heartbeat", false)]
    [InlineData("https://zafiro.kz:8443/api/agent/heartbeat", false)]
    [InlineData("https://evil.example/api/agent/heartbeat", false)]
    [InlineData("https://zafiro.kz.evil.example/api/agent/heartbeat", false)]
    [InlineData("https://user@zafiro.kz/api/agent/heartbeat", false)]
    [InlineData("https://zafiro.kz/api/agent/heartbeat?x=1", false)]
    [InlineData("https://zafiro.kz/api/auth/logout", false)]
    [InlineData("https://zafiro.kz/api/club-computers/5/heartbeat", false)]
    [InlineData("https://zafiro.kz/api/tariffs/abc/purchase", false)]
    [InlineData("https://zafiro.kz/api/tariffs/1/../../agent/exit-pin", true)]
    [InlineData("not a url", false)]
    public void Relay_GoesOnlyToAgentEndpointsOnZafiro(string url, bool allowed)
    {
        Assert.Equal(allowed, VaultProtocol.IsAllowedRelayTarget(url, out _));
    }

    [Fact]
    public void Store_KeepsTheTokenEncrypted()
    {
        var store = new DeviceVaultStore(_dir, restrictAccess: false);
        Assert.Null(store.Load());

        store.Save(Token);

        Assert.Equal(Token, new DeviceVaultStore(_dir, restrictAccess: false).Load());
        Assert.DoesNotContain(Token, File.ReadAllText(Path.Combine(_dir, "device.json")));
    }

    [Fact]
    public async Task Agent_StoresTheToken_AndRequestsWithTheMarkerCarryTheRealTokenOnlyFromTheService()
    {
        var server = new StubHandler((request, _) => StubHandler.Json(HttpStatusCode.OK, "{}"));
        uint? pausedSession = null;
        var vault = StartVault(server, staffExit: session => pausedSession = session);

        Assert.False(vault.HasToken());
        Assert.True(await vault.StoreAsync(Token, CancellationToken.None));
        Assert.True(vault.HasToken());

        var probe = server.Snapshot().First();
        Assert.Equal("https://zafiro.kz/api/agent/heartbeat", probe.Url.AbsoluteUri);
        Assert.Equal(Token, probe.AgentToken);
        Assert.Equal("{}", probe.Body);

        // Агент знает только метку: прямые запросы агента (agentNetwork) не должны получить ни одного запроса.
        var agentNetwork = new StubHandler((_, _) => throw new InvalidOperationException("the marker must not leave the agent"));
        var api = new ZafiroApiClient(new HttpClient(new DeviceVaultHandler(agentNetwork, vault)));
        var pin = await api.VerifyExitPinAsync(DeviceTokens.VaultMarker, "4821", CancellationToken.None, UserToken);

        Assert.True(pin.Success);
        Assert.Empty(agentNetwork.Snapshot());
        var sent = server.Snapshot().Last();
        Assert.Equal("https://zafiro.kz/api/agent/exit-pin", sent.Url.AbsoluteUri);
        Assert.Equal(Token, sent.AgentToken);
        Assert.Equal("Bearer " + UserToken, sent.Authorization);
        Assert.Contains("4821", sent.Body);
        Assert.NotNull(pausedSession); // после выхода по PIN служба не перезапускает агент в этом сеансе
    }

    [Fact]
    public async Task OrdinaryRequests_DoNotGoThroughTheService()
    {
        var agentNetwork = new StubHandler((_, _) => StubHandler.Json(HttpStatusCode.OK, "{}"));
        var client = new HttpClient(new DeviceVaultHandler(agentNetwork, new DeviceVaultClient(_pipe, requireService: false)));

        using var response = await client.PostAsync("https://zafiro.kz/api/auth/logout", new StringContent("{}"));

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.Single(agentNetwork.Snapshot());
    }

    [Fact]
    public async Task Relay_RefusesOtherAddresses_AndDoesNotSendTheToken()
    {
        var server = new StubHandler((_, _) => StubHandler.Json(HttpStatusCode.OK, "{}"));
        var store = new DeviceVaultStore(_dir, restrictAccess: false);
        store.Save(Token);
        var vault = new VaultServer(_pipe, store, new HttpClient(server));

        var reply = await vault.HandleAsync(new VaultMessage { Op = "relay", Method = "POST", Url = "https://zafiro.kz/api/auth/logout", Body = "{}" }, 1, CancellationToken.None);
        var other = await vault.HandleAsync(new VaultMessage { Op = "relay", Method = "POST", Url = "https://evil.example/api/agent/heartbeat", Body = "{}" }, 1, CancellationToken.None);
        var basic = await vault.HandleAsync(new VaultMessage { Op = "relay", Method = "POST", Url = "https://zafiro.kz/api/agent/heartbeat", Authorization = "Basic eDp5", Body = "{}" }, 1, CancellationToken.None);

        Assert.False(reply.Ok);
        Assert.False(other.Ok);
        Assert.False(basic.Ok);
        Assert.Empty(server.Snapshot());
    }

    [Fact]
    public async Task NewToken_ReplacesTheOldOne_OnlyWhenTheServerRevokedTheOldOne()
    {
        var revoked = false;
        var server = new StubHandler((request, _) =>
        {
            var token = request.Headers.GetValues("X-Agent-Token").Single();
            var valid = token == NewToken || (token == Token && !revoked);
            return StubHandler.Json(valid ? HttpStatusCode.OK : HttpStatusCode.Unauthorized, "{}");
        });
        var store = new DeviceVaultStore(_dir, restrictAccess: false);
        store.Save(Token);
        var vault = new VaultServer(_pipe, store, new HttpClient(server));

        var whileOldIsValid = await vault.HandleAsync(new VaultMessage { Op = "store", Token = NewToken }, 1, CancellationToken.None);
        Assert.False(whileOldIsValid.Ok);
        Assert.Equal(Token, store.Load());

        revoked = true; // сотрудник заново подключил ПК: сервер выдал новый токен
        var afterReissue = await vault.HandleAsync(new VaultMessage { Op = "store", Token = NewToken }, 1, CancellationToken.None);
        Assert.True(afterReissue.Ok);
        Assert.Equal(NewToken, store.Load());

        var garbage = await vault.HandleAsync(new VaultMessage { Op = "store", Token = "zag_short" }, 1, CancellationToken.None);
        Assert.False(garbage.Ok);
    }

    [Fact]
    public async Task StoredToken_IsSwappedOnTheServer_SoTheCopyTheAgentSawStopsWorking()
    {
        var server = new StubHandler((request, _) => request.RequestUri!.AbsolutePath == "/api/agent/token/rotate"
            ? StubHandler.Json(HttpStatusCode.OK, $$"""{"agent_token":"{{NewToken}}"}""")
            : StubHandler.Json(HttpStatusCode.OK, "{}"));
        var store = new DeviceVaultStore(_dir, restrictAccess: false);
        var vault = new VaultServer(_pipe, store, new HttpClient(server));

        Assert.True((await vault.HandleAsync(new VaultMessage { Op = "store", Token = Token }, 1, CancellationToken.None)).Ok);
        Assert.Equal(NewToken, store.Load());
        var rotate = server.Snapshot().Single(r => r.Url.AbsolutePath == "/api/agent/token/rotate");
        Assert.Equal(Token, rotate.AgentToken);

        // Агент не получил ответ и прислал тот же токен ещё раз: служба не считает это ошибкой.
        Assert.True((await vault.HandleAsync(new VaultMessage { Op = "store", Token = Token }, 1, CancellationToken.None)).Ok);
        Assert.Equal(NewToken, store.Load());
    }

    [Fact]
    public async Task TokenTheServiceDidNotAccept_IsKeptOnlyInMemory()
    {
        var server = new StubHandler((_, _) => StubHandler.Json(HttpStatusCode.Unauthorized, "{}"));
        var previous = DeviceTokens.Vault;
        DeviceTokens.Vault = StartVault(server);
        try
        {
            var keep = await DeviceTokens.KeepAsync(Token, CancellationToken.None);
            Assert.Equal(Token, keep.Use);
            Assert.False(keep.Persist);
        }
        finally
        {
            DeviceTokens.Vault = previous;
        }
    }

    [Fact]
    public async Task TokenTheServerRejects_IsNotStored()
    {
        var server = new StubHandler((_, _) => StubHandler.Json(HttpStatusCode.Unauthorized, "{}"));
        var vault = StartVault(server);

        Assert.False(await vault.StoreAsync(Token, CancellationToken.None));
        Assert.False(vault.HasToken());
    }

    [Fact]
    public async Task Marker_WithoutAService_IsAnInvalidDeviceToken()
    {
        var agentNetwork = new StubHandler((_, _) => throw new InvalidOperationException("must not be sent"));
        var api = new ZafiroApiClient(new HttpClient(new DeviceVaultHandler(agentNetwork)));
        var previous = DeviceTokens.Vault;
        DeviceTokens.Vault = null;
        try
        {
            var result = await api.VerifyExitPinAsync(DeviceTokens.VaultMarker, "4821", CancellationToken.None);
            Assert.Equal(ApiFailure.DeviceTokenInvalid, result.Failure);
        }
        finally
        {
            DeviceTokens.Vault = previous;
        }
    }

    [Theory]
    [InlineData(1, 3)]
    [InlineData(2, 6)]
    [InlineData(3, 12)]
    [InlineData(5, 48)]
    [InlineData(6, 60)]
    [InlineData(50, 60)]
    public void Watchdog_BacksOffWhenTheAgentKeepsClosing(int failures, int seconds)
    {
        Assert.Equal(TimeSpan.FromSeconds(seconds), Watchdog.RetryDelay(failures));
    }

    [Fact]
    public void Watchdog_RestartsAnAgentThatStoppedReporting()
    {
        var now = new DateTime(2026, 10, 14, 12, 0, 0, DateTimeKind.Utc);
        var started = now.AddMinutes(-5);

        Assert.False(Watchdog.IsUnresponsive(now.AddSeconds(-4), started, now));
        Assert.True(Watchdog.IsUnresponsive(now.AddSeconds(-16), started, now)); // приостановлен или завис
        Assert.True(Watchdog.IsUnresponsive(null, started, now)); // программа с именем агента, которая не отвечает
        Assert.False(Watchdog.IsUnresponsive(null, now.AddSeconds(-10), now)); // только что запущен
    }

    [Fact]
    public async Task Alive_ReportsTheCallingProcessToTheWatchdog()
    {
        (uint Session, uint Pid)? reported = null;
        var vault = new VaultServer(_pipe, new DeviceVaultStore(_dir, restrictAccess: false), new HttpClient(new StubHandler((_, _) => throw new InvalidOperationException())),
            alive: (session, pid) => reported = (session, pid));
        _ = vault.RunAsync(_stop.Token);

        new DeviceVaultClient(_pipe, requireService: false).Alive();
        await Task.Delay(100);

        Assert.Equal((uint)Environment.ProcessId, reported?.Pid);
    }

    private DeviceVaultClient StartVault(StubHandler server, Action<uint>? staffExit = null)
    {
        var vault = new VaultServer(_pipe, new DeviceVaultStore(_dir, restrictAccess: false), new HttpClient(server), staffExit);
        _ = vault.RunAsync(_stop.Token);
        return new DeviceVaultClient(_pipe, requireService: false);
    }
}
