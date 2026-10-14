namespace ZafiroAgent.Models
{
    public enum ComputerState
    {
        Idle,
        InSession,
        Locked,
        ShuttingDown
    }

    public static class ComputerStateExtensions
    {
        // Значения совпадают с контрактом backend.
        public static string ToWire(this ComputerState state) => state switch
        {
            ComputerState.InSession => "in_session",
            ComputerState.Locked => "locked",
            ComputerState.ShuttingDown => "shutting_down",
            _ => "idle"
        };
    }
}
