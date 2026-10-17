using System.Net;
using ZafiroAgent.Service;
using ZafiroAgent.Services;

namespace ZafiroAgent.Tests;

/// <summary>
/// Бездисковая система: образ снят с одного ПК и загружается на всех, а всё записанное на ПК
/// пропадает при перезагрузке. Токен ПК привязан к его UUID, а служба получает токен своего ПК
/// по ключу клуба.
/// </summary>
public sealed class DisklessVaultTests : IDisposable
{
    private const string Token = "zag_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA";
    private const string EnrolledToken = "zag_CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC";
    private const string MasterUuid = "4C4C4544-0042-3510-8051-B4C04F4A3132";
    private const string CloneUuid = "4C4C4544-0099-3510-8051-B4C04F4A3132";
    private static readonly string ClubKey = new('k', 64);
    private static readonly string OtherClubKey = new('o', 64);

    private readonly string _dir = Path.Combine(Path.GetTempPath(), "zafiro-diskless-" + Guid.NewGuid().ToString("N"));
    private readonly string _pipe = "ZafiroAgent.Test." + Guid.NewGuid().ToString("N");

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

    private DeviceVaultStore StoreOn(string? uuid) => new(_dir, restrictAccess: false, () => uuid);

    [Fact]
    public void TokenSavedOnAnotherPc_IsNotUsedOnThisOne()
    {
        StoreOn(MasterUuid).Save(Token);

        Assert.Equal(Token, StoreOn(MasterUuid).Load());
        Assert.Equal(Token, StoreOn(MasterUuid.ToLowerInvariant()).Load());
        Assert.Null(StoreOn(CloneUuid).Load());
    }

    [Fact]
    public void ClubKey_IsKeptEncrypted_AndSurvivesTokenChanges()
    {
        var store = StoreOn(MasterUuid);
        store.SaveClubKey(ClubKey);
        store.Save(Token);
        store.Clear();

        Assert.Null(store.Load());
        Assert.Equal(ClubKey, StoreOn(CloneUuid).LoadClubKey());
        Assert.DoesNotContain(ClubKey, File.ReadAllText(Path.Combine(_dir, "device.json")));
    }

    [Fact]
    public async Task ClubKey_IsAcceptedOnce_SoACustomerProgramCannotSwapTheClub()
    {
        var store = StoreOn(MasterUuid);
        var vault = new VaultServer(_pipe, store, new HttpClient(new StubHandler((_, _) => throw new InvalidOperationException())));

        Assert.False((await vault.HandleAsync(new VaultMessage { Op = "club-key", ClubKey = "short" }, 1, CancellationToken.None)).Ok);
        Assert.True((await vault.HandleAsync(new VaultMessage { Op = "club-key", ClubKey = ClubKey }, 1, CancellationToken.None)).Ok);
        Assert.True((await vault.HandleAsync(new VaultMessage { Op = "club-key", ClubKey = ClubKey }, 1, CancellationToken.None)).Ok);

        var swap = await vault.HandleAsync(new VaultMessage { Op = "club-key", ClubKey = OtherClubKey }, 1, CancellationToken.None);
        Assert.False(swap.Ok);
        Assert.Equal("club_key_locked", swap.Error);
        Assert.Equal(ClubKey, store.LoadClubKey());
    }

    [Fact]
    public async Task ClonedPc_GetsItsOwnToken_WithTheClubKeyAndItsUuid()
    {
        StoreOn(MasterUuid).SaveClubKey(ClubKey);
        StoreOn(MasterUuid).Save(Token); // так выглядит образ: токен ПК, на котором его сняли
        var server = new StubHandler((request, _) => request.RequestUri!.AbsolutePath == "/api/agent/enroll"
            ? StubHandler.Json(HttpStatusCode.OK, $$"""{"agent_token":"{{EnrolledToken}}","computer_id":12}""")
            : StubHandler.Json(HttpStatusCode.OK, "{}"));
        var clone = StoreOn(CloneUuid);
        var vault = new VaultServer(_pipe, clone, new HttpClient(server));

        await vault.KeepOnlineOnceAsync(CancellationToken.None);

        Assert.Equal(EnrolledToken, clone.Load());
        var enroll = server.Snapshot().Single(r => r.Url.AbsolutePath == "/api/agent/enroll");
        Assert.Null(enroll.AgentToken);
        Assert.Contains(ClubKey, enroll.Body);
        Assert.Contains(CloneUuid, enroll.Body);

        // Дальше служба отправляет токен вместе с UUID этого ПК.
        await vault.KeepOnlineOnceAsync(CancellationToken.None);
        var heartbeat = server.Snapshot().Last();
        Assert.Equal("/api/agent/heartbeat", heartbeat.Url.AbsolutePath);
        Assert.Equal(EnrolledToken, heartbeat.AgentToken);
    }

    [Fact]
    public async Task UnregisteredPc_DoesNotAskAgainOnEveryHeartbeat()
    {
        StoreOn(MasterUuid).SaveClubKey(ClubKey);
        var server = new StubHandler((_, _) => StubHandler.Json(HttpStatusCode.NotFound, """{"code":"computer_not_found"}"""));
        var store = StoreOn(MasterUuid);
        var vault = new VaultServer(_pipe, store, new HttpClient(server));

        await vault.KeepOnlineOnceAsync(CancellationToken.None);
        await vault.KeepOnlineOnceAsync(CancellationToken.None);

        Assert.Null(store.Load());
        Assert.Single(server.Snapshot());
    }

    [Fact]
    public async Task TokenTheServerRejects_IsRemoved_AndReplacedWithTheClubKey()
    {
        var store = StoreOn(MasterUuid);
        store.SaveClubKey(ClubKey);
        store.Save(Token);
        var server = new StubHandler((request, _) => request.RequestUri!.AbsolutePath == "/api/agent/enroll"
            ? StubHandler.Json(HttpStatusCode.OK, $$"""{"agent_token":"{{EnrolledToken}}"}""")
            : StubHandler.Json(request.Headers.GetValues("X-Agent-Token").Single() == Token ? HttpStatusCode.Unauthorized : HttpStatusCode.OK, """{"code":"device_mismatch"}"""));
        var vault = new VaultServer(_pipe, store, new HttpClient(server));

        await vault.KeepOnlineOnceAsync(CancellationToken.None);
        Assert.Null(store.Load());
        Assert.Equal(ClubKey, store.LoadClubKey());

        await vault.KeepOnlineOnceAsync(CancellationToken.None);
        Assert.Equal(EnrolledToken, store.Load());
    }

    [Fact]
    public async Task ServiceSendsThisPcsUuid_WithTheToken()
    {
        var store = StoreOn(MasterUuid);
        store.Save(Token);
        string? sentUuid = null;
        var server = new StubHandler((request, _) =>
        {
            sentUuid = request.Headers.TryGetValues(VaultProtocol.DeviceHeader, out var values) ? values.Single() : null;
            return StubHandler.Json(HttpStatusCode.OK, "{}");
        });

        await new VaultServer(_pipe, store, new HttpClient(server)).KeepOnlineOnceAsync(CancellationToken.None);

        Assert.Equal(MasterUuid, sentUuid);
    }
    [Theory]
    // MAC — у каждого ПК свой, даже если UUID одинаковый у всех ПК образа.
    [InlineData("4C4C4544-0042-3510-8051-B4C04F4A3132", "AA:BB:CC:00:11:22", "MAC-AABBCC001122")]
    [InlineData("4C4C4544-0042-3510-8051-B4C04F4A3132", "AA:BB:CC:00:11:23", "MAC-AABBCC001123")]
    [InlineData("03000200-0400-0500-0006-000700080009", "AA:BB:CC:00:11:22", "MAC-AABBCC001122")]
    [InlineData("11111111-1111-1111-1111-111111111111", "aa-bb-cc-00-11-22", "MAC-AABBCC001122")]
    [InlineData(null, "AA:BB:CC:00:11:22", "MAC-AABBCC001122")]
    // Без MAC — UUID, если это не заглушка.
    [InlineData("4C4C4544-0042-3510-8051-B4C04F4A3132", null, "4C4C4544-0042-3510-8051-B4C04F4A3132")]
    [InlineData("4C4C4544-0042-3510-8051-B4C04F4A3132", "00:00:00:00:00:00", "4C4C4544-0042-3510-8051-B4C04F4A3132")]
    [InlineData("03000200-0400-0500-0006-000700080009", null, null)]
    [InlineData(null, "00:00:00:00:00:00", null)]
    public void DeviceId_IsTheMac_UuidOnlyWithoutIt(string? uuid, string? mac, string? expected)
    {
        Assert.Equal(expected, DeviceIdentity.Choose(uuid, mac));
    }

    [Fact]
    public async Task CustomerLogin_GetsTheClubKeyFromTheService_NotFromTheProfile()
    {
        var store = StoreOn(MasterUuid);
        store.SaveClubKey(ClubKey);
        store.Save(Token);
        var server = new StubHandler((_, _) => StubHandler.Json(HttpStatusCode.OK, """{"token":"user-token","club_id":5}"""));
        var vault = new VaultServer(_pipe, store, new HttpClient(server));

        var reply = await vault.HandleAsync(new VaultMessage
        {
            Op = "relay",
            Method = "POST",
            Url = "https://zafiro.kz/api/agent/user/login",
            Body = """{"phone":"+77001234567","password":"secret","club_key":"vault","club_id":5}"""
        }, 1, CancellationToken.None);

        Assert.True(reply.Ok);
        var login = server.Snapshot().Single();
        Assert.Contains(ClubKey, login.Body);
        Assert.DoesNotContain("\"vault\"", login.Body);
        Assert.Null(login.AgentToken); // токен ПК во вход не отправляется
    }

    [Fact]
    public async Task CustomerLogin_WithoutAClubKeyInTheService_IsRefused()
    {
        var server = new StubHandler((_, _) => throw new InvalidOperationException());
        var vault = new VaultServer(_pipe, StoreOn(MasterUuid), new HttpClient(server));

        var reply = await vault.HandleAsync(new VaultMessage { Op = "relay", Method = "POST", Url = "https://zafiro.kz/api/agent/user/login", Body = "{}" }, 1, CancellationToken.None);

        Assert.False(reply.Ok);
        Assert.Equal("no_club_key", reply.Error);
    }

    [Fact]
    public async Task Enrollment_SendsTheMac_SoAPcWithTheSameUuidIsTold_Apart()
    {
        StoreOn(CloneUuid).SaveClubKey(ClubKey);
        var server = new StubHandler((_, _) => StubHandler.Json(HttpStatusCode.Conflict, """{"code":"device_conflict"}"""));
        var vault = new VaultServer(_pipe, StoreOn(CloneUuid), new HttpClient(server), macAddress: () => "AA:BB:CC:00:11:22");

        await vault.KeepOnlineOnceAsync(CancellationToken.None);

        Assert.Contains("AA:BB:CC:00:11:22", server.Snapshot().Single().Body);
    }
}
