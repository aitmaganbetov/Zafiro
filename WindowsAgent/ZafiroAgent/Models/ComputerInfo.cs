using ZafiroAgent.Services;

namespace ZafiroAgent.Models
{
    public sealed record ComputerInfo(long ComputerId, string Number, string Name, string? ZoneName);

    public sealed record HeartbeatRequest(ComputerState State, string? SessionId, string AgentVersion, string DeviceName, HardwareSnapshot Hardware);
}
