using ZafiroAgent.Services;

namespace ZafiroAgent.Models
{
    // AgentToken приходит только сотруднику при регистрации или определении ПК.
    public sealed record ComputerInfo(long ComputerId, string Number, string Name, string? ZoneName, string? AgentToken = null);

    public sealed record HeartbeatRequest(ComputerState State, string? SessionId, string AgentVersion, string DeviceName, HardwareSnapshot Hardware, DateTimeOffset? SessionEndsAtUtc = null);
}
