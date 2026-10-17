using System;
using System.ServiceProcess;
using System.Threading;
using System.Threading.Tasks;
using ZafiroAgent.Services;

namespace ZafiroAgent.Service
{
    /// <summary>
    /// Служба Windows «Zafiro Agent» (ZafiroAgent.exe --service, учётная запись SYSTEM):
    /// хранит токен ПК и перезапускает агент в сеансе клиента. Клиент не может её остановить.
    /// </summary>
    public sealed class AgentService : ServiceBase
    {
        private readonly CancellationTokenSource _stop = new();
        private Watchdog? _watchdog;
        private Task? _work;

        public AgentService()
        {
            ServiceName = VaultProtocol.ServiceName;
            CanHandleSessionChangeEvent = true;
            CanPauseAndContinue = false;
        }

        protected override void OnStart(string[] args)
        {
            var exe = Environment.ProcessPath ?? throw new InvalidOperationException("Agent path is unknown.");
            var forAdministrators = LaunchForAdministrators();
            _watchdog = new Watchdog(exe, forAdministrators);
            SafeLog.Write("[Service] agent starts for administrators too: " + (forAdministrators ? "yes" : "no"));
            var server = new VaultServer(VaultProtocol.PipeName, new DeviceVaultStore(), ZafiroApiClient.CreateDirectHttpClient(), _watchdog.Pause, _watchdog.Resume, _watchdog.Alive, () => DeviceInfo.MacAddress);
            _work = Task.WhenAll(
                Task.Run(() => server.RunAsync(_stop.Token)),
                Task.Run(() => server.KeepOnlineAsync(TimeSpan.FromSeconds(60), _stop.Token)),
                Task.Run(() => _watchdog.RunAsync(_stop.Token)));
            SafeLog.Write("[Service] started");
        }

        /// <summary>
        /// Галочка установщика «Запускать агент и под администратором»: значение пишет установщик в
        /// HKLM (клиент без прав администратора его не изменит), служба читает его при запуске.
        /// </summary>
        private static bool LaunchForAdministrators()
        {
            try
            {
                using var key = Microsoft.Win32.RegistryKey.OpenBaseKey(Microsoft.Win32.RegistryHive.LocalMachine, Microsoft.Win32.RegistryView.Registry64)
                    .OpenSubKey(VaultProtocol.SettingsKey);
                return key?.GetValue("LaunchForAdministrators") is int value && value == 1;
            }
            catch (Exception ex) when (ex is System.Security.SecurityException or UnauthorizedAccessException or System.IO.IOException)
            {
                return false;
            }
        }

        protected override void OnSessionChange(SessionChangeDescription change)
        {
            if (change.Reason is SessionChangeReason.SessionLogon or SessionChangeReason.SessionLogoff)
                _watchdog?.Resume((uint)change.SessionId);
        }

        protected override void OnStop()
        {
            _stop.Cancel();
            try
            {
                _work?.Wait(TimeSpan.FromSeconds(5));
            }
            catch (AggregateException)
            {
            }

            SafeLog.Write("[Service] stopped");
        }
    }
}
