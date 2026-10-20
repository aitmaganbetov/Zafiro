using System.Net;
using System.Security.Cryptography;
using System.Text;
using ZafiroAgent.Service;

namespace ZafiroAgent.Tests;

/// <summary>
/// Автообновление: служба ставит версию, которую прислал сервер, только с GitHub и только если
/// SHA-256 скачанного файла совпал с присланным.
/// </summary>
public sealed class AgentUpdaterTests : IDisposable
{
    private const string Token = "zag_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA";
    private const string Url = "https://github.com/aitmaganbetov/Zafiro/releases/download/agent-v0.0.70/ZafiroAgentSetup-0.0.70.exe";
    private static readonly byte[] Installer = Encoding.UTF8.GetBytes("MZ fake installer");
    private static readonly string InstallerSha = Convert.ToHexString(SHA256.HashData(Installer)).ToLowerInvariant();
    private static readonly DateTime Now = new(2026, 10, 20, 12, 0, 0, DateTimeKind.Utc);

    private readonly string _dir = Path.Combine(Path.GetTempPath(), "zafiro-update-" + Guid.NewGuid().ToString("N"));
    private readonly List<(string Path, string Arguments)> _launched = new();
    private DateTime _now = Now;

    public void Dispose()
    {
        try
        {
            Directory.Delete(_dir, recursive: true);
        }
        catch (IOException)
        {
        }
    }

    private static string OfferJson(string version = "0.0.70", string url = Url, string? sha = null) =>
        $$$"""{"status":"online","agent_update":{"version":"{{{version}}}","url":"{{{url}}}","sha256":"{{{sha ?? InstallerSha}}}"}}""";

    private static HttpResponseMessage File(byte[] bytes, Uri? servedFrom = null)
    {
        var response = new HttpResponseMessage(HttpStatusCode.OK) { Content = new ByteArrayContent(bytes) };
        if (servedFrom is not null) response.RequestMessage = new HttpRequestMessage(HttpMethod.Get, servedFrom);
        return response;
    }

    private AgentUpdater Updater(StubHandler handler, string current = "0.0.62") =>
        new(Path.Combine(_dir, "update"), new HttpClient(handler), current, (path, args) =>
        {
            _launched.Add((path, args));
            return true;
        }, () => _now);

    [Fact]
    public void Offer_IsRead_OnlyWhenComplete_AndFromGitHub()
    {
        Assert.Equal(new AgentUpdateOffer(new Version(0, 0, 70), new Uri(Url), InstallerSha), AgentUpdater.ParseOffer(OfferJson()));

        Assert.Null(AgentUpdater.ParseOffer("""{"agent_update":null}"""));
        Assert.Null(AgentUpdater.ParseOffer("""{"status":"online"}"""));
        Assert.Null(AgentUpdater.ParseOffer("not json"));
        Assert.Null(AgentUpdater.ParseOffer(OfferJson(url: "http://github.com/a.exe")));
        Assert.Null(AgentUpdater.ParseOffer(OfferJson(url: "https://evil.example/ZafiroAgentSetup.exe")));
        Assert.Null(AgentUpdater.ParseOffer(OfferJson(url: "https://github.com.evil.example/a.exe")));
        Assert.Null(AgentUpdater.ParseOffer(OfferJson(sha: "abc")));
        Assert.Null(AgentUpdater.ParseOffer(OfferJson(version: "1.2")));
        Assert.Null(AgentUpdater.ParseOffer(OfferJson(version: "1.2.3.4")));
    }

    [Fact]
    public async Task VerifiedInstaller_IsStartedSilently_WithoutReapplyingTheClientProtection()
    {
        var handler = new StubHandler((_, _) => File(Installer));
        var updater = Updater(handler);

        Assert.True(await updater.TryInstallAsync(AgentUpdater.ParseOffer(OfferJson())!, CancellationToken.None));

        var (path, args) = Assert.Single(_launched);
        Assert.Equal(Path.Combine(_dir, "update", "ZafiroAgentSetup-0.0.70.exe"), path);
        Assert.Equal(Installer, System.IO.File.ReadAllBytes(path));
        Assert.Contains("/VERYSILENT", args);
        Assert.Contains("/MERGETASKS=\"!protect\"", args);
        Assert.Equal(Url, handler.Snapshot().Single().Url.AbsoluteUri);
    }

    [Fact]
    public async Task ChecksumMismatch_IsNotStarted_AndNotRetriedForAnHour()
    {
        var handler = new StubHandler((_, _) => File(Encoding.UTF8.GetBytes("MZ tampered")));
        var updater = Updater(handler);
        var offer = AgentUpdater.ParseOffer(OfferJson())!;

        Assert.False(await updater.TryInstallAsync(offer, CancellationToken.None));
        Assert.False(await updater.TryInstallAsync(offer, CancellationToken.None));

        Assert.Empty(_launched);
        Assert.Single(handler.Snapshot());
        Assert.Empty(Directory.EnumerateFiles(Path.Combine(_dir, "update"), "*.exe"));

        _now = Now.AddHours(1).AddMinutes(1);
        Assert.False(await updater.TryInstallAsync(offer, CancellationToken.None));
        Assert.Equal(2, handler.Snapshot().Length);
    }

    [Fact]
    public async Task SameOrOlderVersion_AndDownloadsServedFromElsewhere_AreNotInstalled()
    {
        var handler = new StubHandler((_, _) => File(Installer, new Uri("https://evil.example/ZafiroAgentSetup.exe")));

        Assert.False(await Updater(handler, current: "0.0.70").TryInstallAsync(AgentUpdater.ParseOffer(OfferJson())!, CancellationToken.None));
        Assert.Empty(handler.Snapshot());

        Assert.False(await Updater(handler).TryInstallAsync(AgentUpdater.ParseOffer(OfferJson())!, CancellationToken.None));
        Assert.Single(handler.Snapshot());
        Assert.Empty(_launched);
    }

    [Fact]
    public void LeftoverInstallers_AreRemoved()
    {
        Directory.CreateDirectory(Path.Combine(_dir, "update"));
        System.IO.File.WriteAllBytes(Path.Combine(_dir, "update", "ZafiroAgentSetup-0.0.61.exe"), Installer);
        System.IO.File.WriteAllText(Path.Combine(_dir, "update", "install.log"), "log");

        Updater(new StubHandler((_, _) => File(Installer))).RemoveLeftovers();

        Assert.Equal(new[] { "install.log" }, Directory.EnumerateFiles(Path.Combine(_dir, "update")).Select(Path.GetFileName).ToArray());
    }

    [Fact]
    public async Task ServiceCheck_AsksForUpdates_AndInstallsTheOffer()
    {
        var store = new DeviceVaultStore(Path.Combine(_dir, "vault"), restrictAccess: false);
        store.Save(Token);
        var server = new StubHandler((request, _) => request.RequestUri!.AbsolutePath == "/api/agent/heartbeat"
            ? StubHandler.Json(HttpStatusCode.OK, OfferJson())
            : File(Installer));
        var vault = new VaultServer("ZafiroAgent.Test." + Guid.NewGuid().ToString("N"), store, new HttpClient(server), updater: Updater(server));

        await vault.KeepOnlineOnceAsync(CancellationToken.None);
        for (var i = 0; i < 100 && _launched.Count == 0; i++) await Task.Delay(20);

        var check = server.Snapshot().First();
        Assert.Equal("""{"can_update":true}""", check.Body);
        Assert.Equal(Token, check.AgentToken);
        Assert.Single(_launched);
        Assert.Equal(Token, store.Load());
    }
}
