using System.Net;
using System.Net.NetworkInformation;
using ZafiroAgent.Services;

namespace ZafiroAgent.Tests;

internal sealed class FakeProbe : IHardwareProbe
{
    public string? Uuid { get; init; } = "11111111-2222-3333-4444-555555555555";
    public string? Cpu { get; init; }
    public int? Cores { get; init; }
    public long? RamBytes { get; init; }
    public IReadOnlyList<string> Gpus { get; init; } = Array.Empty<string>();
    public string? Os { get; init; }
    public IReadOnlyList<NetworkAdapterInfo> Adapters { get; init; } = Array.Empty<NetworkAdapterInfo>();
    public bool ThrowEverywhere { get; init; }
    public int Calls;

    private T Read<T>(Func<T> value)
    {
        Interlocked.Increment(ref Calls);
        return ThrowEverywhere ? throw new InvalidOperationException("wmi unavailable") : value();
    }

    public string? GetDeviceUuid() => Read(() => Uuid);
    public string? GetCpuModel() => Read(() => Cpu);
    public int? GetLogicalProcessorCount() => Read(() => Cores);
    public long? GetTotalRamBytes() => Read(() => RamBytes);
    public IReadOnlyList<string> GetGpuNames() => Read(() => Gpus);
    public string? GetOsCaption() => Read(() => Os);
    public IReadOnlyList<NetworkAdapterInfo> GetNetworkAdapters() => Read(() => Adapters);

    public static NetworkAdapterInfo Adapter(
        string name,
        NetworkInterfaceType type = NetworkInterfaceType.Ethernet,
        OperationalStatus status = OperationalStatus.Up,
        string description = "Realtek PCIe GbE Family Controller",
        string[]? addresses = null,
        bool gateway = true,
        byte[]? mac = null) =>
        new(name, description, type, status, mac ?? new byte[] { 0xAA, 0xBB, 0xCC, 0xDD, 0xEE, 0xFF },
            (addresses ?? new[] { "192.168.1.7" }).Select(IPAddress.Parse).ToList(), gateway);

    public static IReadOnlyList<NetworkAdapterInfo> OneEthernet() => new[] { Adapter("Ethernet") };
}

// Запоминает потоки, из которых читаются характеристики, чтобы проверить работу вне UI-потока.
internal sealed class ThreadRecordingProbe : IHardwareProbe
{
    private readonly System.Collections.Concurrent.ConcurrentBag<int> _threads;

    public ThreadRecordingProbe(System.Collections.Concurrent.ConcurrentBag<int> threads) => _threads = threads;

    private T Record<T>(T value)
    {
        _threads.Add(Environment.CurrentManagedThreadId);
        return value;
    }

    public string? GetDeviceUuid() => Record<string?>("uuid");
    public string? GetCpuModel() => Record<string?>("CPU");
    public int? GetLogicalProcessorCount() => Record<int?>(8);
    public long? GetTotalRamBytes() => Record<long?>(8L * 1024 * 1024 * 1024);
    public IReadOnlyList<string> GetGpuNames() => Record<IReadOnlyList<string>>(new[] { "GPU" });
    public string? GetOsCaption() => Record<string?>("Microsoft Windows 11 Pro");
    public IReadOnlyList<NetworkAdapterInfo> GetNetworkAdapters() => Record(FakeProbe.OneEthernet());
}

public sealed class WindowsProbeSmokeTests
{
    private readonly Xunit.Abstractions.ITestOutputHelper _output;

    public WindowsProbeSmokeTests(Xunit.Abstractions.ITestOutputHelper output) => _output = output;

    // Реальный запрос к WMI и сетевым адаптерам этой машины; в сеть ничего не отправляется.
    [Fact]
    public void RealProbe_DoesNotThrow_AndReturnsPlausibleValues()
    {
        var probe = new WindowsHardwareProbe();

        var snapshot = new HardwareCollector(probe).GetSnapshot();
        var uuid = probe.GetDeviceUuid();

        _output.WriteLine($"uuid present: {uuid is not null}");
        _output.WriteLine($"mac present: {snapshot.MacAddress is not null}, ip present: {snapshot.LocalIp is not null}");
        _output.WriteLine($"cpu: {snapshot.CpuModel}; cores: {snapshot.CpuCores}; ram_mb: {snapshot.RamTotalMb}");
        _output.WriteLine($"gpu: {snapshot.GpuModel}; os: {snapshot.OsVersion}");

        if (snapshot.CpuCores is { } cores) Assert.InRange(cores, 1, 1024);
        if (snapshot.RamTotalMb is { } ram) Assert.InRange(ram, 256, 16L * 1024 * 1024);
        if (snapshot.MacAddress is { } mac) Assert.Matches("^([0-9A-F]{2}:){5}[0-9A-F]{2}$", mac);
        if (snapshot.LocalIp is { } ip) Assert.True(System.Net.IPAddress.TryParse(ip, out _));
        if (uuid is not null) Assert.Matches("^[0-9A-Fa-f-]{32,36}$", uuid);
    }
}

public sealed class HardwareTests
{
    private static readonly DateTime Now = new(2026, 10, 13, 12, 0, 0, DateTimeKind.Utc);

    [Fact]
    public void Snapshot_CollectsAllCharacteristics()
    {
        var probe = new FakeProbe
        {
            Cpu = "  AMD Ryzen 7   5800X  ",
            Cores = 16,
            RamBytes = 64L * 1024 * 1024 * 1024,
            Gpus = new[] { "NVIDIA GeForce RTX 4070" },
            Os = "Microsoft Windows 11 Pro",
            Adapters = FakeProbe.OneEthernet()
        };

        var snapshot = new HardwareCollector(probe, () => Now).GetSnapshot();

        Assert.Equal("AMD Ryzen 7 5800X", snapshot.CpuModel);
        Assert.Equal(16, snapshot.CpuCores);
        Assert.Equal(65536, snapshot.RamTotalMb);
        Assert.Equal("NVIDIA GeForce RTX 4070", snapshot.GpuModel);
        Assert.Equal("Windows 11 Pro", snapshot.OsVersion);
        Assert.Equal("AA:BB:CC:DD:EE:FF", snapshot.MacAddress);
        Assert.Equal("192.168.1.7", snapshot.LocalIp);
    }

    [Theory]
    [InlineData("Microsoft Windows 11 Pro", "Windows 11 Pro")]
    [InlineData("Майкрософт Windows 10 Pro для рабочих станций", "Windows 10 Pro для рабочих станций")]
    [InlineData("Windows Server 2022", "Windows Server 2022")]
    [InlineData("   ", null)]
    public void OsVersion_DropsVendorPrefix(string caption, string? expected)
    {
        var snapshot = new HardwareCollector(new FakeProbe { Os = caption }, () => Now).GetSnapshot();

        Assert.Equal(expected, snapshot.OsVersion);
    }

    [Fact]
    public void Snapshot_WithUnavailableData_UsesNullInsteadOfInventedValues()
    {
        var snapshot = new HardwareCollector(new FakeProbe(), () => Now).GetSnapshot();

        Assert.Equal(HardwareSnapshot.Empty, snapshot);
    }

    [Fact]
    public void Snapshot_WhenProbeThrows_DoesNotThrowAndReturnsNulls()
    {
        var snapshot = new HardwareCollector(new FakeProbe { ThrowEverywhere = true }, () => Now).GetSnapshot();

        Assert.Equal(HardwareSnapshot.Empty, snapshot);
    }

    [Theory]
    [InlineData(0L)]
    [InlineData(-1L)]
    public void Snapshot_IgnoresNonPositiveRamAndCores(long value)
    {
        var snapshot = new HardwareCollector(new FakeProbe { RamBytes = value, Cores = (int)value }, () => Now).GetSnapshot();

        Assert.Null(snapshot.RamTotalMb);
        Assert.Null(snapshot.CpuCores);
    }

    [Fact]
    public void Snapshot_HardwareIsCachedButNetworkIsRefreshedAfterItsTtl()
    {
        var now = Now;
        var adapters = new List<NetworkAdapterInfo> { FakeProbe.Adapter("Ethernet", addresses: new[] { "192.168.1.7" }) };
        var probe = new FakeProbe { Cpu = "CPU", Adapters = adapters };
        var collector = new HardwareCollector(probe, () => now, TimeSpan.FromHours(6), TimeSpan.FromSeconds(30));

        collector.GetSnapshot();
        var callsAfterFirst = probe.Calls;

        adapters[0] = FakeProbe.Adapter("Ethernet", addresses: new[] { "10.0.0.5" });
        Assert.Equal("192.168.1.7", collector.GetSnapshot().LocalIp);
        Assert.Equal(callsAfterFirst, probe.Calls);

        now = Now.AddSeconds(31);
        Assert.Equal("10.0.0.5", collector.GetSnapshot().LocalIp);
        Assert.Equal(callsAfterFirst + 1, probe.Calls);

        now = Now.AddHours(7);
        collector.GetSnapshot();
        Assert.True(probe.Calls > callsAfterFirst + 1);
    }

    [Fact]
    public void Network_ExcludesLoopbackDisabledAndVirtualAdapters()
    {
        var adapters = new[]
        {
            FakeProbe.Adapter("Loopback", NetworkInterfaceType.Loopback, addresses: new[] { "127.0.0.1" }),
            FakeProbe.Adapter("Wi-Fi", NetworkInterfaceType.Wireless80211, OperationalStatus.Down, addresses: new[] { "192.168.1.50" }),
            FakeProbe.Adapter("vEthernet (WSL)", description: "Hyper-V Virtual Ethernet Adapter", addresses: new[] { "172.20.0.1" }),
            FakeProbe.Adapter("VMware Network Adapter VMnet1", description: "VMware Virtual Ethernet Adapter", addresses: new[] { "192.168.56.1" }),
            FakeProbe.Adapter("Ethernet", mac: new byte[] { 0x10, 0x20, 0x30, 0x40, 0x50, 0x60 }, addresses: new[] { "192.168.1.7" })
        };

        var (mac, ip) = NetworkSelector.Select(adapters);

        Assert.Equal("10:20:30:40:50:60", mac);
        Assert.Equal("192.168.1.7", ip);
    }

    [Fact]
    public void Network_PrefersAdapterWithGateway()
    {
        var adapters = new[]
        {
            FakeProbe.Adapter("Ethernet 2", addresses: new[] { "10.10.10.2" }, gateway: false, mac: new byte[] { 1, 1, 1, 1, 1, 1 }),
            FakeProbe.Adapter("Ethernet", addresses: new[] { "192.168.1.7" }, gateway: true, mac: new byte[] { 2, 2, 2, 2, 2, 2 })
        };

        var (mac, ip) = NetworkSelector.Select(adapters);

        Assert.Equal("02:02:02:02:02:02", mac);
        Assert.Equal("192.168.1.7", ip);
    }

    [Fact]
    public void Network_SkipsApipaAndPrefersIpv4OverIpv6()
    {
        var onlyApipa = new[] { FakeProbe.Adapter("Ethernet", addresses: new[] { "169.254.10.20" }) };
        Assert.Equal((null, null), NetworkSelector.Select(onlyApipa));

        var mixed = new[] { FakeProbe.Adapter("Ethernet", addresses: new[] { "169.254.10.20", "2001:db8::7", "192.168.1.7" }) };
        Assert.Equal("192.168.1.7", NetworkSelector.Select(mixed).Ip);
    }

    [Fact]
    public void Network_SupportsIpv6WhenNoUsableIpv4_ButNotLinkLocal()
    {
        var global = new[] { FakeProbe.Adapter("Ethernet", addresses: new[] { "fe80::1", "2001:db8::7" }) };
        Assert.Equal("2001:db8::7", NetworkSelector.Select(global).Ip);

        var linkLocalOnly = new[] { FakeProbe.Adapter("Ethernet", addresses: new[] { "fe80::1" }) };
        Assert.Equal((null, null), NetworkSelector.Select(linkLocalOnly));
    }

    [Fact]
    public void Network_WithoutAdapters_ReturnsNull()
    {
        Assert.Equal((null, null), NetworkSelector.Select(Array.Empty<NetworkAdapterInfo>()));
    }

    [Theory]
    [InlineData(new byte[] { 0, 0, 0, 0, 0, 0 })]
    [InlineData(new byte[] { 1, 2, 3 })]
    [InlineData(null)]
    public void FormatMac_RejectsEmptyOrMalformedAddresses(byte[]? bytes)
    {
        Assert.Null(NetworkSelector.FormatMac(bytes));
    }

    [Fact]
    public void GpuNames_AreDeduplicatedAndLengthBounded()
    {
        var names = new[] { "NVIDIA GeForce RTX 4070", "nvidia geforce rtx 4070", "  ", "Intel UHD Graphics 770" };
        Assert.Equal("NVIDIA GeForce RTX 4070; Intel UHD Graphics 770", HardwareCollector.JoinGpuNames(names));

        var many = Enumerable.Range(1, 100).Select(i => $"Graphics Adapter Number {i}");
        var joined = HardwareCollector.JoinGpuNames(many);
        Assert.NotNull(joined);
        Assert.True(joined!.Length <= HardwareCollector.MaxTextLength);

        Assert.Null(HardwareCollector.JoinGpuNames(Array.Empty<string>()));
        Assert.Null(HardwareCollector.JoinGpuNames(null));
    }
}
