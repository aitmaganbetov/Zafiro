using System.Collections.Concurrent;
using System.Net;
using System.Text.Json;
using ZafiroAgent.Configuration;
using ZafiroAgent.Models;
using ZafiroAgent.Security;
using ZafiroAgent.Services;

namespace ZafiroAgent.Tests;

public sealed class HeartbeatTests : IDisposable
{
    private const string Token = "api-token-SECRET-abc";
    private static readonly DateTime Now = new(2026, 10, 13, 12, 0, 0, DateTimeKind.Utc);

    private const string ResolveJson = """{"computer_id":45,"number":"7","name":"PC-07","zone":{"id":3,"name":"VIP"}}""";

    private static string HeartbeatJson(int interval = 30) =>
        """{"computer_id":45,"status":"online","last_seen_at":"2026-10-13T12:00:00Z","recommended_interval_seconds":""" + interval +
        ""","number":"7","zone":{"id":3,"name":"VIP"}}""";

    private static readonly HeartbeatOptions Fast = new()
    {
        MinInterval = TimeSpan.FromMilliseconds(10),
        MaxInterval = TimeSpan.FromMilliseconds(100),
        DefaultInterval = TimeSpan.FromMilliseconds(50),
        RetryDelay = TimeSpan.FromMilliseconds(20),
        MaxRetryDelay = TimeSpan.FromMilliseconds(80),
        ClientErrorDelay = TimeSpan.FromMilliseconds(50)
    };

    // Длинный интервал: повторная отправка возможна только по сигналу о смене состояния.
    private static readonly HeartbeatOptions Slow = Fast with { MaxInterval = TimeSpan.FromSeconds(60) };

    private readonly string _dir = Path.Combine(Path.GetTempPath(), "zafiro-hb-tests-" + Guid.NewGuid().ToString("N"));
    private readonly ConcurrentQueue<Exception> _errors = new();
    private readonly ConcurrentQueue<HeartbeatResponse> _beats = new();

    public void Dispose()
    {
        if (Directory.Exists(_dir)) Directory.Delete(_dir, recursive: true);
    }

    private static bool IsResolve(HttpRequestMessage r) => r.RequestUri!.AbsolutePath.EndsWith("/club-computers/resolve");

    private static bool IsRegister(HttpRequestMessage r) => r.RequestUri!.AbsolutePath.EndsWith("/club-computers/register");

    private const string NotFoundJson = """{"code":"computer_not_found","message":"Компьютер не найден в текущем клубе."}""";

    // resolve не находит ПК; register отвечает registerResponse().
    private static Func<HttpRequestMessage, string?, HttpResponseMessage> UnknownComputer(Func<HttpResponseMessage> registerResponse) =>
        (r, _) => IsResolve(r) ? StubHandler.Json(HttpStatusCode.NotFound, NotFoundJson)
            : IsRegister(r) ? registerResponse()
            : StubHandler.Json(HttpStatusCode.OK, HeartbeatJson());

    internal static HardwareCollector Hardware() => new(
        new FakeProbe
        {
            Cpu = "AMD Ryzen 7 5800X",
            Cores = 16,
            RamBytes = 64L * 1024 * 1024 * 1024,
            Gpus = new[] { "NVIDIA GeForce RTX 4070" },
            Os = "Microsoft Windows 11 Pro",
            Adapters = FakeProbe.OneEthernet()
        },
        () => Now);

    private static HttpResponseMessage Default(HttpRequestMessage r, string? _) =>
        StubHandler.Json(HttpStatusCode.OK, IsResolve(r) ? ResolveJson : HeartbeatJson());

    private static AgentConfig SeedConfig(SecureStorageService secure, DateTime tokenExpires) => new()
    {
        ClubId = "123",
        ClubName = "Asia",
        ApiBaseUrl = "https://zafiro.kz/api/",
        LoginEndpoint = "https://zafiro.kz/api/auth/login",
        ProtectedClubKey = secure.Protect("install-key"),
        ProtectedApiToken = secure.Protect(Token),
        TokenExpiresAtUtc = tokenExpires
    };

    private (HeartbeatService Service, StubHandler Handler, ConfigurationService Config) Create(
        Func<HttpRequestMessage, string?, HttpResponseMessage>? respond = null,
        HeartbeatOptions? options = null,
        DateTime? tokenExpires = null,
        Action<AgentConfig>? configure = null)
    {
        var config = new ConfigurationService(Path.Combine(_dir, "agent.config.json"));
        var secure = new SecureStorageService();
        var seed = SeedConfig(secure, tokenExpires ?? Now.AddDays(10));
        configure?.Invoke(seed);
        config.Save(seed);

        var handler = new StubHandler(respond ?? Default);
        var api = new ZafiroApiClient(new HttpClient(handler), () => Now);
        var setup = new ClubSetupService(api, config, secure, "TEST-PC", () => Now);
        var service = new HeartbeatService(config, setup, api, "TEST-PC", () => "device-uuid", "1.2.3", options ?? Fast, () => Now, Hardware());
        service.OnError += _errors.Enqueue;
        service.OnHeartbeat += _beats.Enqueue;
        return (service, handler, config);
    }

    private static async Task WaitUntil(Func<bool> condition, int timeoutMs = 5000)
    {
        var deadline = DateTime.UtcNow.AddMilliseconds(timeoutMs);
        while (!condition())
        {
            if (DateTime.UtcNow > deadline) throw new TimeoutException("Условие не выполнено за отведённое время.");
            await Task.Delay(10);
        }
    }

    private static JsonDocument Body(RecordedRequest request) => JsonDocument.Parse(request.Body!);

    private static Task Finish(HeartbeatService service) => service.StopAsync(null, TimeSpan.FromSeconds(1));

    [Fact]
    public async Task Start_ResolvesComputerThenSendsStatusWithBearerToken()
    {
        var (service, handler, config) = Create();

        service.Start();
        await WaitUntil(() => handler.Snapshot().Length >= 2);
        await Finish(service);

        var requests = handler.Snapshot();
        var resolve = requests[0];
        Assert.Equal(HttpMethod.Post, resolve.Method);
        Assert.Equal("https://zafiro.kz/api/club-computers/resolve", resolve.Url.AbsoluteUri);
        Assert.Equal("Bearer " + Token, resolve.Authorization);
        using (var body = Body(resolve))
        {
            Assert.Equal("TEST-PC", body.RootElement.GetProperty("device_name").GetString());
            Assert.Equal("device-uuid", body.RootElement.GetProperty("device_id").GetString());
        }

        var heartbeat = requests[1];
        Assert.Equal("https://zafiro.kz/api/club-computers/45/heartbeat", heartbeat.Url.AbsoluteUri);
        Assert.Equal("Bearer " + Token, heartbeat.Authorization);
        using (var body = Body(heartbeat))
        {
            Assert.Equal("idle", body.RootElement.GetProperty("state").GetString());
            Assert.Equal("1.2.3", body.RootElement.GetProperty("agent_version").GetString());
            Assert.Equal("TEST-PC", body.RootElement.GetProperty("device_name").GetString());
            Assert.Equal(JsonValueKind.Null, body.RootElement.GetProperty("session_id").ValueKind);
        }

        var saved = config.Load();
        Assert.Equal("45", saved.ComputerId);
        Assert.Equal("7", saved.ComputerNumber);
        Assert.Equal("VIP", saved.Zone);
        Assert.True(_beats.TryPeek(out var first));
        Assert.Equal("online", first!.Status);
        Assert.Equal("VIP", first.Zone);
    }

    [Fact]
    public async Task SetState_InSession_SendsSessionIdImmediately_ThenIdleDropsIt()
    {
        var (service, handler, _) = Create(options: Slow);

        service.Start();
        await WaitUntil(() => handler.Snapshot().Length >= 2);

        service.SetState(ComputerState.InSession, "sess-1");
        await WaitUntil(() => handler.Snapshot().Length >= 3, 3000);
        Assert.True(await service.WaitForStateHeartbeatAsync(ComputerState.InSession, "sess-1", TimeSpan.FromSeconds(1), CancellationToken.None));
        using (var body = Body(handler.Snapshot()[2]))
        {
            Assert.Equal("in_session", body.RootElement.GetProperty("state").GetString());
            Assert.Equal("sess-1", body.RootElement.GetProperty("session_id").GetString());
        }

        service.SetState(ComputerState.Idle);
        await WaitUntil(() => handler.Snapshot().Length >= 4, 3000);
        using (var body = Body(handler.Snapshot()[3]))
        {
            Assert.Equal("idle", body.RootElement.GetProperty("state").GetString());
            Assert.Equal(JsonValueKind.Null, body.RootElement.GetProperty("session_id").ValueKind);
        }

        await Finish(service);
    }

    [Fact]
    public async Task SetSessionEnd_SendsUtcEndTimeImmediately()
    {
        var (service, handler, _) = Create(options: Slow);
        var sessionEnd = new DateTimeOffset(2026, 10, 14, 10, 30, 0, TimeSpan.Zero);

        service.Start();
        await WaitUntil(() => handler.Snapshot().Length >= 2);
        service.SetState(ComputerState.InSession, "sess-1");
        await WaitUntil(() => handler.Snapshot().Length >= 3, 3000);

        service.SetSessionEnd(sessionEnd);
        await WaitUntil(() => handler.Snapshot().Length >= 4, 3000);

        using var body = Body(handler.Snapshot()[3]);
        Assert.True(body.RootElement.GetProperty("session_ends_at").TryGetDateTimeOffset(out var actual));
        Assert.Equal(sessionEnd, actual);

        await Finish(service);
    }

    [Fact]
    public async Task SetState_WithSameState_DoesNotTriggerExtraHeartbeat()
    {
        var (service, handler, _) = Create(options: Slow);

        service.Start();
        await WaitUntil(() => handler.Snapshot().Length >= 2);
        service.SetState(ComputerState.Idle);
        await Task.Delay(300);

        Assert.Equal(2, handler.Snapshot().Length);
        await Finish(service);
    }

    [Fact]
    public async Task Heartbeat_ExpiredSignIn_StopsTheLoopUntilTheNextSignIn()
    {
        var (service, handler, _) = Create((r, _) => IsResolve(r)
            ? StubHandler.Json(HttpStatusCode.OK, ResolveJson)
            : StubHandler.Json(HttpStatusCode.Unauthorized, "{}"));

        service.Start();
        await WaitUntil(() => !_errors.IsEmpty);
        await Task.Delay(300);

        var error = Assert.IsType<ZafiroApiException>(Assert.Single(_errors));
        Assert.Equal(ApiFailure.TokenExpired, error.Failure);
        Assert.DoesNotContain(Token, error.Message);
        Assert.Equal(2, handler.Snapshot().Length);
        await Finish(service);
    }

    // Иначе ПК пропадает из панели и не узнаёт о сессиях, купленных в панели.
    [Theory]
    [InlineData(HttpStatusCode.PaymentRequired, ApiFailure.SubscriptionInactive)]
    [InlineData(HttpStatusCode.Forbidden, ApiFailure.Forbidden)]
    [InlineData(HttpStatusCode.UnprocessableEntity, ApiFailure.InvalidRequest)]
    public async Task Heartbeat_OtherClientErrors_AreReported_AndTheLoopKeepsGoing(HttpStatusCode status, ApiFailure expected)
    {
        var (service, handler, _) = Create((r, _) => IsResolve(r)
            ? StubHandler.Json(HttpStatusCode.OK, ResolveJson)
            : StubHandler.Json(status, "{}"));

        service.Start();
        await WaitUntil(() => handler.Snapshot().Length >= 4);

        var error = Assert.IsType<ZafiroApiException>(_errors.First());
        Assert.Equal(expected, error.Failure);
        await Finish(service);
    }

    [Fact]
    public async Task Heartbeat_SessionMismatch_DropsTheLocalSession_AndReportsIdle()
    {
        var beats = 0;
        var (service, handler, _) = Create((r, _) =>
        {
            if (IsResolve(r)) return StubHandler.Json(HttpStatusCode.OK, ResolveJson);
            return Interlocked.Increment(ref beats) == 1
                ? StubHandler.Json(HttpStatusCode.UnprocessableEntity, "{}")
                : StubHandler.Json(HttpStatusCode.OK, HeartbeatJson());
        });

        service.SetState(ComputerState.InSession, "old-session");
        service.Start();
        await WaitUntil(() => handler.Snapshot().Length >= 3);

        var retry = handler.Snapshot()[2];
        Assert.Contains("\"state\":\"idle\"", retry.Body);
        await Finish(service);
    }

    [Theory]
    [InlineData(HttpStatusCode.Unauthorized, ApiFailure.TokenExpired)]
    [InlineData(HttpStatusCode.PaymentRequired, ApiFailure.SubscriptionInactive)]
    [InlineData(HttpStatusCode.Forbidden, ApiFailure.Forbidden)]
    public async Task Resolve_Errors_StopWithoutHeartbeatOrRetry(HttpStatusCode status, ApiFailure expected)
    {
        var (service, handler, _) = Create((_, _) => StubHandler.Json(status, "{}"));

        service.Start();
        await WaitUntil(() => !_errors.IsEmpty);
        await Task.Delay(300);

        var error = Assert.IsType<ZafiroApiException>(Assert.Single(_errors));
        Assert.Equal(expected, error.Failure);
        Assert.Single(handler.Snapshot());
        await Finish(service);
    }

    [Theory]
    [InlineData(HttpStatusCode.ServiceUnavailable)]
    [InlineData(HttpStatusCode.TooManyRequests)]
    [InlineData(HttpStatusCode.BadGateway)]
    public async Task Heartbeat_TemporaryServerErrors_AreRetried(HttpStatusCode status)
    {
        var failures = 0;
        var (service, handler, _) = Create((r, _) =>
        {
            if (IsResolve(r)) return StubHandler.Json(HttpStatusCode.OK, ResolveJson);
            return Interlocked.Increment(ref failures) == 1
                ? StubHandler.Json(status, "{}")
                : StubHandler.Json(HttpStatusCode.OK, HeartbeatJson());
        });

        service.Start();
        await WaitUntil(() => !_beats.IsEmpty);
        await Finish(service);

        Assert.True(handler.Snapshot().Length >= 3);
        var error = Assert.IsType<ZafiroApiException>(Assert.Single(_errors));
        Assert.Equal(ApiFailure.Network, error.Failure);
    }

    [Fact]
    public async Task NetworkFailure_IsRetried_AndDoesNotStopLoop()
    {
        var calls = 0;
        var (service, _, _) = Create((r, _) =>
        {
            if (Interlocked.Increment(ref calls) <= 2) throw new HttpRequestException("boom");
            return IsResolve(r)
                ? StubHandler.Json(HttpStatusCode.OK, ResolveJson)
                : StubHandler.Json(HttpStatusCode.OK, HeartbeatJson());
        });

        service.Start();
        await WaitUntil(() => !_beats.IsEmpty);
        await Finish(service);

        Assert.True(_errors.Count >= 2);
        Assert.All(_errors, e => Assert.Equal(ApiFailure.Network, Assert.IsType<ZafiroApiException>(e).Failure));
    }

    [Fact]
    public async Task InvalidResponse_IsRetried()
    {
        var calls = 0;
        var (service, _, _) = Create((r, _) =>
        {
            if (IsResolve(r)) return StubHandler.Json(HttpStatusCode.OK, ResolveJson);
            return Interlocked.Increment(ref calls) == 1
                ? StubHandler.Json(HttpStatusCode.OK, "not json")
                : StubHandler.Json(HttpStatusCode.OK, HeartbeatJson());
        });

        service.Start();
        await WaitUntil(() => !_beats.IsEmpty);
        await Finish(service);

        Assert.Equal(ApiFailure.InvalidResponse, Assert.IsType<ZafiroApiException>(Assert.Single(_errors)).Failure);
    }

    [Theory]
    [InlineData(ComputerState.Locked, "locked")]
    [InlineData(ComputerState.ShuttingDown, "shutting_down")]
    public async Task StopAsync_SendsFinalStatus(ComputerState finalState, string expected)
    {
        var (service, handler, _) = Create(options: Slow);

        service.Start();
        await WaitUntil(() => handler.Snapshot().Length >= 2);
        await service.StopAsync(finalState, TimeSpan.FromSeconds(2));

        var last = handler.Snapshot()[^1];
        Assert.EndsWith("/club-computers/45/heartbeat", last.Url.AbsolutePath);
        using var body = Body(last);
        Assert.Equal(expected, body.RootElement.GetProperty("state").GetString());
        Assert.Equal(JsonValueKind.Null, body.RootElement.GetProperty("session_id").ValueKind);
    }

    [Fact]
    public async Task StopAsync_WithoutResolvedComputer_SendsNothing()
    {
        var (service, handler, _) = Create();

        await service.StopAsync(ComputerState.Locked, TimeSpan.FromSeconds(1));

        Assert.Empty(handler.Snapshot());
    }

    [Fact]
    public async Task ExpiredToken_ReportsErrorAndSendsNothing()
    {
        var (service, handler, _) = Create(tokenExpires: Now.AddMinutes(-1));

        service.Start();
        await WaitUntil(() => !_errors.IsEmpty);

        Assert.Equal(ApiFailure.TokenExpired, Assert.IsType<ZafiroApiException>(Assert.Single(_errors)).Failure);
        Assert.Empty(handler.Snapshot());
    }

    [Fact]
    public async Task ResolvedComputer_DoesNotResurrectTokenClearedByLogout()
    {
        ConfigurationService? config = null;
        var (service, _, cfg) = Create((r, _) =>
        {
            if (IsResolve(r)) config!.Update(c => c.ProtectedApiToken = string.Empty);
            return Default(r, null);
        });
        config = cfg;

        service.Start();
        await WaitUntil(() => !_beats.IsEmpty);
        await Finish(service);

        var saved = cfg.Load();
        Assert.Empty(saved.ProtectedApiToken);
        Assert.Equal("45", saved.ComputerId);
    }

    [Fact]
    public async Task Heartbeat_BodyContainsExactSnakeCaseFieldsFromContract()
    {
        var (service, handler, _) = Create();

        service.Start();
        await WaitUntil(() => handler.Snapshot().Length >= 2);
        await Finish(service);

        using var body = Body(handler.Snapshot()[1]);
        var root = body.RootElement;
        Assert.Equal(
            new[]
            {
                "state", "session_id", "agent_version", "device_name", "mac_address", "local_ip",
                "cpu_model", "cpu_cores", "ram_total_mb", "gpu_model", "os_version"
            },
            root.EnumerateObject().Select(p => p.Name).ToArray());

        Assert.Equal("AA:BB:CC:DD:EE:FF", root.GetProperty("mac_address").GetString());
        Assert.Equal("192.168.1.7", root.GetProperty("local_ip").GetString());
        Assert.Equal("AMD Ryzen 7 5800X", root.GetProperty("cpu_model").GetString());
        Assert.Equal(16, root.GetProperty("cpu_cores").GetInt32());
        Assert.Equal(65536, root.GetProperty("ram_total_mb").GetInt64());
        Assert.Equal("NVIDIA GeForce RTX 4070", root.GetProperty("gpu_model").GetString());
        Assert.Equal("Windows 11 Pro", root.GetProperty("os_version").GetString());
    }

    [Fact]
    public async Task Heartbeat_WithUnavailableHardware_SendsNullsAndStillWorks()
    {
        var config = new ConfigurationService(Path.Combine(_dir, "agent.config.json"));
        var secure = new SecureStorageService();
        config.Save(SeedConfig(secure, Now.AddDays(10)));
        var handler = new StubHandler(Default);
        var api = new ZafiroApiClient(new HttpClient(handler), () => Now);
        var setup = new ClubSetupService(api, config, secure, "TEST-PC", () => Now);
        var service = new HeartbeatService(config, setup, api, "TEST-PC", () => null, "1.2.3", Fast, () => Now,
            new HardwareCollector(new FakeProbe { ThrowEverywhere = true }, () => Now));
        service.OnHeartbeat += _beats.Enqueue;

        service.Start();
        await WaitUntil(() => handler.Snapshot().Length >= 2);
        await Finish(service);

        using (var resolve = Body(handler.Snapshot()[0]))
            Assert.False(resolve.RootElement.TryGetProperty("device_id", out _));

        using var body = Body(handler.Snapshot()[1]);
        foreach (var field in new[] { "mac_address", "local_ip", "cpu_model", "cpu_cores", "ram_total_mb", "gpu_model", "os_version" })
            Assert.Equal(JsonValueKind.Null, body.RootElement.GetProperty(field).ValueKind);
        Assert.Equal("idle", body.RootElement.GetProperty("state").GetString());
    }

    [Fact]
    public async Task FullFlow_ResolveClub_Login_ResolveComputer_Heartbeat()
    {
        var handler = new StubHandler((r, _) => r.RequestUri!.AbsolutePath switch
        {
            "/api/clubs/resolve" => StubHandler.Json(HttpStatusCode.OK, """{"club_id":123,"club_name":"Asia","api_base_url":"https://zafiro.kz/api","login_endpoint":"https://zafiro.kz/api/auth/login"}"""),
            "/api/agent/user/login" => StubHandler.Json(HttpStatusCode.OK, $$"""{"token":"{{Token}}","club_id":123,"club_name":"Asia"}"""),
            "/api/club-computers/resolve" => StubHandler.Json(HttpStatusCode.OK, ResolveJson),
            _ => StubHandler.Json(HttpStatusCode.OK, HeartbeatJson())
        });
        var config = new ConfigurationService(Path.Combine(_dir, "agent.config.json"));
        var api = new ZafiroApiClient(new HttpClient(handler), () => Now);
        var setup = new ClubSetupService(api, config, new SecureStorageService(), "TEST-PC", () => Now);
        var service = new HeartbeatService(config, setup, api, "TEST-PC", () => "device-uuid", "1.2.3", Fast, () => Now, Hardware());
        service.OnHeartbeat += _beats.Enqueue;

        var login = await setup.SetupAsync("install-key", "+77001234567", "pwd-123456", CancellationToken.None);
        Assert.True(login.Success);

        service.Start();
        await WaitUntil(() => handler.Snapshot().Length >= 4);
        await Finish(service);

        var paths = handler.Snapshot().Take(4).Select(r => r.Url.AbsolutePath).ToArray();
        Assert.Equal(new[] { "/api/clubs/resolve", "/api/agent/user/login", "/api/club-computers/resolve", "/api/club-computers/45/heartbeat" }, paths);

        Assert.All(handler.Snapshot().Skip(2), r => Assert.Equal("Bearer " + Token, r.Authorization));
        Assert.All(handler.Snapshot().Take(2), r => Assert.Null(r.Authorization));
        Assert.Equal("45", config.Load().ComputerId);
        Assert.Equal("7", setup.ComputerNumber);
    }

    [Fact]
    public async Task Heartbeat_ReadsServerFieldsAndDoesNotComputeOfflineLocally()
    {
        var (service, _, _) = Create((r, _) => IsResolve(r)
            ? StubHandler.Json(HttpStatusCode.OK, ResolveJson)
            : StubHandler.Json(HttpStatusCode.OK, """{"computer_id":45,"status":"busy","connection_status":"offline","last_seen_at":"2020-01-01T00:00:00Z","recommended_interval_seconds":30,"number":"7","zone":{"id":3,"name":"VIP"},"hardware":{"remote_ip":"203.0.113.5"}}"""));

        service.Start();
        await WaitUntil(() => !_beats.IsEmpty);
        await Finish(service);

        Assert.True(_beats.TryPeek(out var beat));
        Assert.Equal("busy", beat!.Status);
        Assert.Equal("offline", beat.ConnectionStatus);
        Assert.Equal("7", beat.Number);
        Assert.All(_beats, b => Assert.NotEqual("offline", b.Status));
    }

    [Fact]
    public async Task InvalidConfiguration_StopsWithoutNetworkRequests()
    {
        foreach (var apiBase in new[] { "http://zafiro.kz/api/", "https://evil.example/api/", "not a url" })
        {
            var (service, handler, config) = Create();
            config.Update(c => c.ApiBaseUrl = apiBase);

            service.Start();
            await WaitUntil(() => !_errors.IsEmpty);

            Assert.Equal(ApiFailure.InvalidEndpoint, Assert.IsType<ZafiroApiException>(_errors.First()).Failure);
            Assert.Empty(handler.Snapshot());
            while (_errors.TryDequeue(out _)) { }
        }
    }

    [Fact]
    public async Task NotConfiguredClub_ReportsErrorWithoutRequests()
    {
        var (service, handler, config) = Create();
        config.Save(new AgentConfig());

        service.Start();
        await WaitUntil(() => !_errors.IsEmpty);

        Assert.Equal(ApiFailure.NotConfigured, Assert.IsType<ZafiroApiException>(Assert.Single(_errors)).Failure);
        Assert.Empty(handler.Snapshot());
    }

    [Theory]
    [InlineData(HttpStatusCode.Created)]
    [InlineData(HttpStatusCode.OK)]
    public async Task UnknownComputer_IsRegistered_ThenHeartbeatStarts(HttpStatusCode registerStatus)
    {
        var (service, handler, config) = Create(UnknownComputer(() => StubHandler.Json(registerStatus, ResolveJson)));

        service.Start();
        await WaitUntil(() => handler.Snapshot().Length >= 3);
        await Finish(service);

        var requests = handler.Snapshot();
        Assert.Equal(
            new[] { "/api/club-computers/resolve", "/api/club-computers/register", "/api/club-computers/45/heartbeat" },
            requests.Take(3).Select(r => r.Url.AbsolutePath).ToArray());
        Assert.All(requests.Take(3), r => Assert.Equal("Bearer " + Token, r.Authorization));

        using (var body = Body(requests[1]))
        {
            Assert.Equal(new[] { "device_name", "device_id", "mac_address" }, body.RootElement.EnumerateObject().Select(p => p.Name).ToArray());
            Assert.Equal("TEST-PC", body.RootElement.GetProperty("device_name").GetString());
            Assert.Equal("device-uuid", body.RootElement.GetProperty("device_id").GetString());
            Assert.Equal("AA:BB:CC:DD:EE:FF", body.RootElement.GetProperty("mac_address").GetString());
        }

        var saved = config.Load();
        Assert.Equal("45", saved.ComputerId);
        Assert.Equal("7", saved.ComputerNumber);
        Assert.Equal("VIP", saved.Zone);
        Assert.Empty(_errors);
    }

    [Fact]
    public async Task KnownComputer_IsNotRegisteredAgain()
    {
        var (service, handler, _) = Create();

        service.Start();
        await WaitUntil(() => handler.Snapshot().Length >= 3);
        await Finish(service);

        Assert.DoesNotContain(handler.Snapshot(), r => r.Url.AbsolutePath.EndsWith("/club-computers/register"));
    }

    [Fact]
    public async Task Register_WithoutDeviceIdAndMac_OmitsThoseFields()
    {
        var config = new ConfigurationService(Path.Combine(_dir, "agent.config.json"));
        var secure = new SecureStorageService();
        config.Save(SeedConfig(secure, Now.AddDays(10)));
        var handler = new StubHandler(UnknownComputer(() => StubHandler.Json(HttpStatusCode.Created, ResolveJson)));
        var api = new ZafiroApiClient(new HttpClient(handler), () => Now);
        var setup = new ClubSetupService(api, config, secure, "TEST-PC", () => Now);
        var service = new HeartbeatService(config, setup, api, "TEST-PC", () => null, "1.2.3", Fast, () => Now,
            new HardwareCollector(new FakeProbe { ThrowEverywhere = true }, () => Now));

        service.Start();
        await WaitUntil(() => handler.Snapshot().Length >= 3);
        await Finish(service);

        using var body = Body(handler.Snapshot()[1]);
        Assert.Equal(new[] { "device_name" }, body.RootElement.EnumerateObject().Select(p => p.Name).ToArray());
    }

    [Theory]
    [InlineData("""{"message":"The route api/club-computers/resolve could not be found."}""")]
    [InlineData("""<html>Not Found</html>""")]
    [InlineData("""{"code":"something_else"}""")]
    [InlineData("")]
    public async Task Resolve404WithoutComputerNotFoundCode_MeansMissingRoute_AndDoesNotRegister(string body)
    {
        var (service, handler, _) = Create((_, _) => StubHandler.Json(HttpStatusCode.NotFound, body));

        service.Start();
        await WaitUntil(() => !_errors.IsEmpty);
        await Task.Delay(200);

        var error = Assert.IsType<ZafiroApiException>(Assert.Single(_errors));
        Assert.Equal(ApiFailure.EndpointNotFound, error.Failure);
        Assert.Single(handler.Snapshot());
        await Finish(service);
    }

    [Theory]
    [InlineData(HttpStatusCode.Forbidden, ApiFailure.Forbidden)]
    [InlineData(HttpStatusCode.Unauthorized, ApiFailure.TokenExpired)]
    [InlineData(HttpStatusCode.PaymentRequired, ApiFailure.SubscriptionInactive)]
    [InlineData(HttpStatusCode.UnprocessableEntity, ApiFailure.InvalidRequest)]
    [InlineData(HttpStatusCode.Conflict, ApiFailure.InvalidRequest)]
    [InlineData(HttpStatusCode.NotFound, ApiFailure.EndpointNotFound)]
    public async Task RegisterErrors_StopWithoutRetry_AndOnlyOneRegisterAttempt(HttpStatusCode status, ApiFailure expected)
    {
        var (service, handler, _) = Create(UnknownComputer(() => StubHandler.Json(status, "{}")));

        service.Start();
        await WaitUntil(() => !_errors.IsEmpty);
        await Task.Delay(300);

        var error = Assert.IsType<ZafiroApiException>(Assert.Single(_errors));
        Assert.Equal(expected, error.Failure);
        Assert.DoesNotContain(Token, error.Message);
        Assert.Equal(2, handler.Snapshot().Length);
        Assert.Equal(1, handler.Snapshot().Count(r => r.Url.AbsolutePath.EndsWith("/club-computers/register")));
        await Finish(service);
    }

    [Fact]
    public async Task RegisterForbidden_TellsUserToAskAdministrator()
    {
        var (service, _, _) = Create(UnknownComputer(() => StubHandler.Json(HttpStatusCode.Forbidden, "{}")));

        service.Start();
        await WaitUntil(() => !_errors.IsEmpty);
        await Finish(service);

        var error = Assert.IsType<ZafiroApiException>(Assert.Single(_errors));
        Assert.Equal(ZafiroApiClient.RegisterForbidden, error.Message);
        Assert.Contains("администратор", error.Message);
    }

    [Theory]
    [InlineData(HttpStatusCode.ServiceUnavailable)]
    [InlineData(HttpStatusCode.TooManyRequests)]
    public async Task TemporaryRegisterFailure_IsRetriedWithBackoff_ThenSucceeds(HttpStatusCode status)
    {
        var attempts = 0;
        var (service, handler, config) = Create(UnknownComputer(() =>
            Interlocked.Increment(ref attempts) == 1
                ? StubHandler.Json(status, "{}")
                : StubHandler.Json(HttpStatusCode.Created, ResolveJson)));

        service.Start();
        await WaitUntil(() => !_beats.IsEmpty);
        await Finish(service);

        Assert.Equal(ApiFailure.Network, Assert.IsType<ZafiroApiException>(Assert.Single(_errors)).Failure);
        Assert.True(handler.Snapshot().Count(r => r.Url.AbsolutePath.EndsWith("/club-computers/register")) >= 2);
        Assert.Equal("45", config.Load().ComputerId);
    }

    [Fact]
    public async Task Heartbeat404_DependsOnCode()
    {
        foreach (var (body, expected) in new[]
                 {
                     (NotFoundJson, ApiFailure.ComputerNotFound),
                     ("""{"message":"The route could not be found."}""", ApiFailure.EndpointNotFound)
                 })
        {
            var (service, handler, _) = Create((r, _) => IsResolve(r)
                ? StubHandler.Json(HttpStatusCode.OK, ResolveJson)
                : StubHandler.Json(HttpStatusCode.NotFound, body));

            service.Start();
            await WaitUntil(() => !_errors.IsEmpty);
            await Task.Delay(150);

            // Heartbeat продолжается: ПК не найден — агент определяет его заново.
            Assert.Equal(expected, Assert.IsType<ZafiroApiException>(_errors.First()).Failure);
            Assert.True(handler.Snapshot().Length >= 2);
            await Finish(service);
            while (_errors.TryDequeue(out _)) { }
        }
    }

    [Fact]
    public async Task RegisterComputer_ParsesNumericFields_AndRejectsMalformedBody()
    {
        var session = new ClubSession(123, "Asia", new Uri("https://zafiro.kz/api"), Token, Now.AddDays(1));

        var good = new ZafiroApiClient(new HttpClient(new StubHandler((_, _) =>
            StubHandler.Json(HttpStatusCode.Created, """{"computer_id":45,"number":7,"name":"PC-07","zone":{"id":3,"name":"VIP"}}"""))), () => Now);
        var ok = await good.RegisterComputerAsync(session, "TEST-PC", "device-uuid", null, CancellationToken.None);
        Assert.True(ok.Success);
        Assert.Equal(new ComputerInfo(45, "7", "PC-07", "VIP"), ok.Value);

        var bad = new ZafiroApiClient(new HttpClient(new StubHandler((_, _) =>
            StubHandler.Json(HttpStatusCode.Created, """{"number":"7"}"""))), () => Now);
        var failed = await bad.RegisterComputerAsync(session, "TEST-PC", null, null, CancellationToken.None);
        Assert.False(failed.Success);
        Assert.Equal(ApiFailure.InvalidResponse, failed.Failure);
    }

    [Fact]
    public async Task Heartbeat_Token401_IsReportedOnceWithoutRetryLoop()
    {
        var (service, handler, _) = Create((r, _) => IsResolve(r)
            ? StubHandler.Json(HttpStatusCode.OK, ResolveJson)
            : StubHandler.Json(HttpStatusCode.Unauthorized, "{}"));

        service.Start();
        await WaitUntil(() => !_errors.IsEmpty);
        await Task.Delay(300);

        Assert.Equal(ApiFailure.TokenExpired, Assert.IsType<ZafiroApiException>(Assert.Single(_errors)).Failure);
        Assert.Equal(2, handler.Snapshot().Length);
    }

    [Theory]
    [InlineData(1, 10)]
    [InlineData(2, 20)]
    [InlineData(3, 40)]
    [InlineData(4, 80)]
    [InlineData(5, 120)]
    [InlineData(30, 120)]
    public void BackoffDelay_GrowsExponentiallyUpToLimit(int failures, int expectedSeconds)
    {
        Assert.Equal(TimeSpan.FromSeconds(expectedSeconds), HeartbeatService.BackoffDelay(failures, new HeartbeatOptions()));
    }

    [Fact]
    public async Task NetworkOutage_IsRetriedWithBackoff_ThenResumes()
    {
        var calls = 0;
        var stamps = new ConcurrentQueue<DateTime>();
        var (service, _, _) = Create((r, _) =>
        {
            if (IsResolve(r)) return StubHandler.Json(HttpStatusCode.OK, ResolveJson);
            stamps.Enqueue(DateTime.UtcNow);
            if (Interlocked.Increment(ref calls) <= 4) throw new HttpRequestException("network down");
            return StubHandler.Json(HttpStatusCode.OK, HeartbeatJson());
        });

        service.Start();
        await WaitUntil(() => !_beats.IsEmpty);
        await Finish(service);

        Assert.True(_errors.Count >= 4);
        var times = stamps.ToArray();
        var gaps = times.Zip(times.Skip(1), (a, b) => b - a).Take(4).ToArray();
        Assert.True(gaps[^1] >= gaps[0], "Паузы между повторами не должны уменьшаться.");
    }

    [Fact]
    public async Task NetworkRequestsAndHardwareProbing_RunOffTheCallingThread()
    {
        var callerThread = Environment.CurrentManagedThreadId;
        var threads = new ConcurrentBag<(int Id, bool HasContext)>();
        var probeThreads = new ConcurrentBag<int>();

        var (service, handler, _) = Create((r, _) =>
        {
            threads.Add((Environment.CurrentManagedThreadId, SynchronizationContext.Current is not null));
            return Default(r, null);
        });
        var config = new ConfigurationService(Path.Combine(_dir, "agent.config.json"));
        var secure = new SecureStorageService();
        var api = new ZafiroApiClient(new HttpClient(handler), () => Now);
        var setup = new ClubSetupService(api, config, secure, "TEST-PC", () => Now);
        var probe = new ThreadRecordingProbe(probeThreads);
        service = new HeartbeatService(config, setup, api, "TEST-PC", () => { probeThreads.Add(Environment.CurrentManagedThreadId); return "device-uuid"; },
            "1.2.3", Fast, () => Now, new HardwareCollector(probe, () => Now));

        var previous = SynchronizationContext.Current;
        SynchronizationContext.SetSynchronizationContext(new SynchronizationContext());
        try
        {
            service.Start();
        }
        finally
        {
            SynchronizationContext.SetSynchronizationContext(previous);
        }

        await WaitUntil(() => handler.Snapshot().Length >= 2);
        await Finish(service);

        Assert.NotEmpty(threads);
        Assert.All(threads, t => Assert.NotEqual(callerThread, t.Id));
        Assert.All(threads, t => Assert.False(t.HasContext));
        Assert.NotEmpty(probeThreads);
        Assert.All(probeThreads, id => Assert.NotEqual(callerThread, id));
    }

    [Fact]
    public async Task Heartbeat_WhenSetStateCalledFromUiLikeThread_DoesNotBlockCaller()
    {
        var (service, handler, _) = Create(options: Slow);
        service.Start();
        await WaitUntil(() => handler.Snapshot().Length >= 2);

        var stopwatch = System.Diagnostics.Stopwatch.StartNew();
        for (var i = 0; i < 100; i++) service.SetState(i % 2 == 0 ? ComputerState.InSession : ComputerState.Idle, "s" + i);
        stopwatch.Stop();

        Assert.True(stopwatch.ElapsedMilliseconds < 500);
        await Finish(service);
    }

    [Theory]
    [InlineData(0, 30)]
    [InlineData(-5, 30)]
    [InlineData(1, 5)]
    [InlineData(45, 45)]
    [InlineData(120, 120)]
    [InlineData(3600, 3600)]
    [InlineData(100000, 3600)]
    public void ClampInterval_FollowsServerRecommendation_WithSafeBounds(int recommended, int expectedSeconds)
    {
        var result = HeartbeatService.ClampInterval(recommended, new HeartbeatOptions());

        Assert.Equal(TimeSpan.FromSeconds(expectedSeconds), result);
    }

    [Fact]
    public async Task ResolveComputer_AcceptsNumericFieldsAndMissingZone()
    {
        var handler = new StubHandler((_, _) => StubHandler.Json(HttpStatusCode.OK, """{"computer_id":"45","number":7,"name":"PC-07"}"""));
        var api = new ZafiroApiClient(new HttpClient(handler), () => Now);
        var session = new ClubSession(123, "Asia", new Uri("https://zafiro.kz/api"), Token, Now.AddDays(1));

        var result = await api.ResolveComputerAsync(session, "TEST-PC", "device-uuid", CancellationToken.None);

        Assert.True(result.Success);
        Assert.Equal(new ComputerInfo(45, "7", "PC-07", null), result.Value);
    }

    [Fact]
    public async Task Heartbeat_ReadsTheServerClock()
    {
        var json = HeartbeatJson()[..^1] + ""","server_time":"2026-10-14T12:30:15.000+00:00"}""";
        var (service, _, _) = Create((r, _) => StubHandler.Json(HttpStatusCode.OK, IsResolve(r) ? ResolveJson : json));

        service.Start();
        await WaitUntil(() => !_beats.IsEmpty);
        await Finish(service);

        Assert.Equal(new DateTimeOffset(2026, 10, 14, 12, 30, 15, TimeSpan.Zero), _beats.First().ServerTimeUtc);
    }

    [Fact]
    public async Task Heartbeat_ReadsTheClubNameAndLogo()
    {
        var json = HeartbeatJson()[..^1] + ""","club":{"name":"Arena","logo_url":"https://zafiro.kz/club-logos/1/logo.png"}}""";
        var (service, _, _) = Create((r, _) => StubHandler.Json(HttpStatusCode.OK, IsResolve(r) ? ResolveJson : json));

        service.Start();
        await WaitUntil(() => !_beats.IsEmpty);
        await Finish(service);

        var beat = _beats.First();
        Assert.True(beat.ClubReported);
        Assert.Equal("Arena", beat.ClubName);
        Assert.Equal("https://zafiro.kz/club-logos/1/logo.png", beat.ClubLogoUrl);
    }

    [Fact]
    public async Task Heartbeat_ReadsTheClubLanguageAndTranslationsVersion()
    {
        var json = HeartbeatJson()[..^1] + ""","club":{"name":"Arena","locale":"kk","translations_version":12,"languages":[{"code":"ru","name":"Русский"},{"code":"kk","name":"Қазақша"},{"name":"broken"}]}}""";
        var (service, _, _) = Create((r, _) => StubHandler.Json(HttpStatusCode.OK, IsResolve(r) ? ResolveJson : json));

        service.Start();
        await WaitUntil(() => !_beats.IsEmpty);
        await Finish(service);

        var beat = _beats.First();
        Assert.Equal("kk", beat.ClubLocale);
        Assert.Equal(12, beat.TranslationsVersion);
        Assert.Equal(new[] { "ru", "kk" }, beat.ClubLanguages!.Select(l => l.Code));
    }

    [Theory]
    [InlineData(""","club":{"name":"Arena","logo_url":null}}""", true)]
    [InlineData("}", false)]
    public async Task Heartbeat_WithoutLogo_ReportsOnlyWhatTheServerSent(string tail, bool reported)
    {
        var json = HeartbeatJson()[..^1] + tail;
        var (service, _, _) = Create((r, _) => StubHandler.Json(HttpStatusCode.OK, IsResolve(r) ? ResolveJson : json));

        service.Start();
        await WaitUntil(() => !_beats.IsEmpty);
        await Finish(service);

        var beat = _beats.First();
        Assert.Equal(reported, beat.ClubReported);
        Assert.Null(beat.ClubLogoUrl);
    }

    [Fact]
    public async Task ResolveComputer_WithMalformedBody_ReturnsInvalidResponse()
    {
        var handler = new StubHandler((_, _) => StubHandler.Json(HttpStatusCode.OK, """{"number":"7"}"""));
        var api = new ZafiroApiClient(new HttpClient(handler), () => Now);
        var session = new ClubSession(123, "Asia", new Uri("https://zafiro.kz/api"), Token, Now.AddDays(1));

        var result = await api.ResolveComputerAsync(session, "TEST-PC", "device-uuid", CancellationToken.None);

        Assert.False(result.Success);
        Assert.Equal(ApiFailure.InvalidResponse, result.Failure);
    }

    private const string DeviceToken = "zag_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA";

    private static bool IsDeviceHeartbeat(HttpRequestMessage r) => r.RequestUri!.AbsolutePath.EndsWith("/agent/heartbeat");

    [Fact]
    public async Task DeviceToken_FromResolve_IsStoredProtected_AndUsedInsteadOfUserToken()
    {
        var resolve = """{"computer_id":45,"number":"7","name":"PC-07","zone":{"id":3,"name":"VIP"},"agent_token":"DEVICE"}""".Replace("DEVICE", DeviceToken);
        var (service, handler, config) = Create((r, _) => StubHandler.Json(HttpStatusCode.OK, IsResolve(r) ? resolve : HeartbeatJson()));

        service.Start();
        await WaitUntil(() => handler.Snapshot().Length >= 2);
        await Finish(service);

        var beat = handler.Snapshot()[1];
        Assert.Equal("https://zafiro.kz/api/agent/heartbeat", beat.Url.AbsoluteUri);
        Assert.Null(beat.Authorization);
        Assert.Equal(DeviceToken, beat.AgentToken);
        Assert.Equal(DeviceToken, new SecureStorageService().Unprotect(config.Load().ProtectedDeviceToken));
        Assert.DoesNotContain(DeviceToken, File.ReadAllText(Path.Combine(_dir, "agent.config.json")));
    }

    [Fact]
    public async Task RejectedDeviceToken_IsForgotten_AndHeartbeatFallsBackToUserToken()
    {
        var (service, handler, config) = Create(
            (r, _) => IsResolve(r) ? StubHandler.Json(HttpStatusCode.OK, ResolveJson)
                : IsDeviceHeartbeat(r) ? StubHandler.Json(HttpStatusCode.Unauthorized, """{"code":"invalid_agent_token"}""")
                : StubHandler.Json(HttpStatusCode.OK, HeartbeatJson()),
            configure: cfg => cfg.ProtectedDeviceToken = new SecureStorageService().Protect(DeviceToken));

        service.Start();
        await WaitUntil(() => handler.Snapshot().Any(x => x.Url.AbsolutePath.EndsWith("/club-computers/45/heartbeat")));
        await Finish(service);

        Assert.Equal(string.Empty, config.Load().ProtectedDeviceToken);
        Assert.Equal("Bearer " + Token, handler.Snapshot().Last(x => x.Url.AbsolutePath.EndsWith("/club-computers/45/heartbeat")).Authorization);
        Assert.Empty(_errors);
    }

    [Fact]
    public async Task Heartbeat_ReportsPaidSessionFromServer()
    {
        const string json = """{"computer_id":45,"status":"online","recommended_interval_seconds":30,"session":{"id":"paid-1","ends_at":"2026-10-13T13:30:00+00:00","user_id":7},"session_expired":false}""";
        var (service, _, _) = Create((r, _) => StubHandler.Json(HttpStatusCode.OK, IsResolve(r) ? ResolveJson : json));

        service.Start();
        await WaitUntil(() => !_beats.IsEmpty);
        await Finish(service);

        Assert.True(_beats.TryPeek(out var beat));
        Assert.True(beat!.SessionReported);
        Assert.Equal("paid-1", beat.SessionId);
        Assert.Equal(new DateTimeOffset(2026, 10, 13, 13, 30, 0, TimeSpan.Zero), beat.SessionEndsAtUtc);
        Assert.False(beat.SessionExpired);
    }

    [Fact]
    public async Task Heartbeat_ReportsExpiredSession_AndOldServersReportNothing()
    {
        const string expired = """{"computer_id":45,"status":"online","recommended_interval_seconds":30,"session":null,"session_expired":true}""";
        var (service, _, _) = Create((r, _) => StubHandler.Json(HttpStatusCode.OK, IsResolve(r) ? ResolveJson : expired));
        service.Start();
        await WaitUntil(() => !_beats.IsEmpty);
        await Finish(service);
        Assert.True(_beats.TryDequeue(out var beat));
        Assert.True(beat!.SessionReported);
        Assert.Null(beat.SessionId);
        Assert.True(beat.SessionExpired);

        var (old, _, _) = Create();
        old.Start();
        await WaitUntil(() => !_beats.IsEmpty);
        await Finish(old);
        Assert.True(_beats.TryDequeue(out var oldBeat));
        Assert.False(oldBeat!.SessionReported);
    }
}
