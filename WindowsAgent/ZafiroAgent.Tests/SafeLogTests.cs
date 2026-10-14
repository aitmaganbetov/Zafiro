using System.Collections.Concurrent;
using System.Net;
using ZafiroAgent.Models;
using ZafiroAgent.Security;
using ZafiroAgent.Services;

namespace ZafiroAgent.Tests;

public sealed class SafeLogTests : IDisposable
{
    private const string Key = "install-key-LOGSECRET-9";
    private const string Password = "p@ss-LOGSECRET-9";
    private const string Token = "token-LOGSECRET-9";

    private readonly ConcurrentQueue<string> _lines = new();

    public SafeLogTests()
    {
        SafeLog.UseSink(_lines.Enqueue);
    }

    public void Dispose() => SafeLog.UseSink(null);

    [Theory]
    [InlineData("Authorization: Bearer abc.DEF-123_xyz=", "abc.DEF-123_xyz")]
    [InlineData("{\"password\":\"hunter2-secret\"}", "hunter2-secret")]
    [InlineData("{\"club_key\":\"key-value-123\",\"phone\":\"+7700\"}", "key-value-123")]
    [InlineData("{\"token\":\"tok-value-456\"}", "tok-value-456")]
    [InlineData("{\"access_token\":\"acc-value-789\"}", "acc-value-789")]
    [InlineData("login failed password=hunter2-secret next", "hunter2-secret")]
    [InlineData("club_key: key-value-123, phone=+7700", "key-value-123")]
    [InlineData("body password\":\"hunter2-secret\"", "hunter2-secret")]
    public void Redact_MasksKnownPatterns(string text, string secret)
    {
        var result = SafeLog.Redact(text);

        Assert.DoesNotContain(secret, result);
        Assert.Contains("***", result);
    }

    [Fact]
    public void Redact_MasksRegisteredSecretsAnywhere()
    {
        SafeLog.Register("registered-secret-777");

        Assert.Equal("value=*** end", SafeLog.Redact("value=registered-secret-777 end"));
    }

    [Fact]
    public void Redact_KeepsOrdinaryTextAndHandlesEmpty()
    {
        Assert.Equal("[Heartbeat] sent state=idle", SafeLog.Redact("[Heartbeat] sent state=idle"));
        Assert.Equal(string.Empty, SafeLog.Redact(null));
        Assert.Equal(string.Empty, SafeLog.Redact(string.Empty));
    }

    [Fact]
    public void Write_SendsOnlyRedactedTextToSink()
    {
        SafeLog.Register(Token);

        SafeLog.Write($"request failed: Bearer {Token} password\":\"{Password}\"");
        SafeLog.Write("{\"password\":\"" + Password + "\"}");

        var text = string.Join('\n', _lines);
        Assert.DoesNotContain(Token, text);
        Assert.DoesNotContain(Password, text);
    }

    [Fact]
    public async Task FullFlow_WithFailingServer_NeverWritesSecretsToLog()
    {
        var dir = Path.Combine(Path.GetTempPath(), "zafiro-log-" + Guid.NewGuid().ToString("N"));
        try
        {
            // Сервер намеренно возвращает секреты в теле ответа, чтобы проверить, что они не попадают в журнал.
            var echo = $"{{\"echo\":\"{Key} {Password} {Token}\",\"token\":\"{Token}\",\"password\":\"{Password}\"}}";
            var handler = new StubHandler((r, _) => r.RequestUri!.AbsolutePath.EndsWith("/clubs/resolve")
                ? StubHandler.Json(HttpStatusCode.OK, """{"club_id":123,"club_name":"Asia","api_base_url":"https://zafiro.kz/api","login_endpoint":"https://zafiro.kz/api/auth/login"}""")
                : r.RequestUri.AbsolutePath.EndsWith("/agent/user/login")
                    ? StubHandler.Json(HttpStatusCode.OK, $$"""{"token":"{{Token}}","club_id":123,"club_name":"Asia"}""")
                    : StubHandler.Json(HttpStatusCode.InternalServerError, echo));

            var config = new ConfigurationService(Path.Combine(dir, "agent.config.json"));
            var api = new ZafiroApiClient(new HttpClient(handler), () => DateTime.UtcNow);
            var setup = new ClubSetupService(api, config, new SecureStorageService(), "TEST-PC");
            var service = new HeartbeatService(config, setup, api, "TEST-PC", () => null, "1.0.0",
                new HeartbeatOptions { RetryDelay = TimeSpan.FromMilliseconds(10), MaxRetryDelay = TimeSpan.FromMilliseconds(20) },
                null, new HardwareCollector(new FakeProbe()));
            var errors = new ConcurrentQueue<Exception>();
            service.OnError += errors.Enqueue;

            Assert.True((await setup.SetupAsync(Key, "+77001234567", Password, CancellationToken.None)).Success);
            service.Start();
            await Task.Delay(400);
            await service.StopAsync(null, TimeSpan.FromSeconds(1));

            Assert.NotEmpty(errors);
            var logged = string.Join('\n', _lines) + "\n" + string.Join('\n', errors.Select(e => e.Message));
            Assert.NotEmpty(_lines);
            Assert.DoesNotContain(Key, logged);
            Assert.DoesNotContain(Password, logged);
            Assert.DoesNotContain(Token, logged);
        }
        finally
        {
            if (Directory.Exists(dir)) Directory.Delete(dir, recursive: true);
        }
    }
}
