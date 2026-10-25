using System.Net;
using System.Text.Json;
using ZafiroAgent.Configuration;
using ZafiroAgent.Infrastructure;
using ZafiroAgent.Models;
using ZafiroAgent.Services;

namespace ZafiroAgent.Tests;

/// <summary>Сотрудник перезагружает, выключает и включает ПК из панели и приложения: команды приходят в heartbeat.</summary>
public sealed class StaffCommandsTests
{
    private const string Token = "1|customer-token";
    private static readonly DateTime Now = new(2026, 10, 21, 12, 0, 0, DateTimeKind.Utc);

    private static JsonElement Root(string json) => JsonDocument.Parse(json).RootElement;

    [Fact]
    public void Commands_AreReadOnlyWhenKnown()
    {
        Assert.Equal("restart", StaffCommands.PowerCommand(Root("""{"power_command":"restart"}""")));
        Assert.Equal("shutdown", StaffCommands.PowerCommand(Root("""{"power_command":"shutdown"}""")));
        Assert.Null(StaffCommands.PowerCommand(Root("""{"power_command":"format c:"}""")));
        Assert.Null(StaffCommands.PowerCommand(Root("""{"power_command":null}""")));
        Assert.Null(StaffCommands.PowerCommand(Root("""{}""")));

        Assert.Equal("Закрываемся", StaffCommands.StaffMessage(Root("""{"staff_message":"  Закрываемся "}""")));
        Assert.Equal(300, StaffCommands.StaffMessage(Root($"""{{"staff_message":"{new string('a', 400)}"}}"""))!.Length);
        Assert.Null(StaffCommands.StaffMessage(Root("""{"staff_message":"   "}""")));
        Assert.Null(StaffCommands.StaffMessage(Root("""{"staff_message":7}""")));

        Assert.Equal(new[] { "AA:BB:CC:00:11:22" }, StaffCommands.WakeMacs(Root("""{"wake_macs":["aa:bb:cc:00:11:22","not-a-mac",7]}""")));
        Assert.Empty(StaffCommands.WakeMacs(Root("""{"wake_macs":null}""")));
    }

    [Fact]
    public void MagicPacket_IsSixFfAndSixteenTimesTheMac()
    {
        var packet = WakeOnLan.MagicPacket("AA:BB:CC:00:11:22");

        Assert.Equal(102, packet.Length);
        Assert.All(packet.Take(6), b => Assert.Equal(0xFF, b));
        for (var i = 0; i < 16; i++)
            Assert.Equal(new byte[] { 0xAA, 0xBB, 0xCC, 0x00, 0x11, 0x22 }, packet.Skip(6 + i * 6).Take(6).ToArray());
    }

    [Fact]
    public async Task Heartbeat_AsksForCommands_AndReadsThem()
    {
        var handler = new StubHandler((_, _) => StubHandler.Json(HttpStatusCode.OK,
            """{"computer_id":45,"status":"online","recommended_interval_seconds":30,"power_command":"shutdown","wake_macs":["AA:BB:CC:00:11:22"]}"""));
        var api = new ZafiroApiClient(new HttpClient(handler), () => Now);
        var session = new ClubSession(1, "Club", new Uri("https://zafiro.kz/api/"), Token, Now.AddDays(1));

        var heartbeat = await api.SendHeartbeatAsync(session, 45, new HeartbeatRequest(ComputerState.Idle, null, "1.2.3", "TEST-PC", HardwareSnapshot.Empty), CancellationToken.None, "device-token");

        Assert.Contains("\"commands\":true", Assert.Single(handler.Snapshot()).Body);
        Assert.Equal("shutdown", heartbeat.PowerCommand);
        Assert.Equal(new[] { "AA:BB:CC:00:11:22" }, heartbeat.WakeMacs);
    }
}
