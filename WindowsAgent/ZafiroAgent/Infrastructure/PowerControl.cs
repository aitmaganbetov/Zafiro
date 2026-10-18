using System;
using System.IO;
using ZafiroAgent.Services;

namespace ZafiroAgent.Infrastructure
{
    /// <summary>Выключение и перезагрузка ПК: после сессии (настройка клуба) и кнопками на экране входа.</summary>
    public static class PowerControl
    {
        /// <summary>shutdown.exe с /f: открытые игры не мешают перезагрузке. false — Windows не дал выключить ПК.</summary>
        public static bool Run(bool restart)
        {
            try
            {
                using var process = System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo
                {
                    FileName = Path.Combine(Environment.SystemDirectory, "shutdown.exe"),
                    Arguments = (restart ? "/r" : "/s") + " /f /t 0",
                    UseShellExecute = false,
                    CreateNoWindow = true
                });
                if (process is null) return false;
                if (!process.WaitForExit(10000)) return true; // Windows уже выключается
                if (process.ExitCode == 0) return true;
                SafeLog.Write("[Power] shutdown.exe exited with code " + process.ExitCode);
            }
            catch (Exception ex)
            {
                SafeLog.Write("[Power] shutdown.exe failed: " + ex.Message);
            }

            return false;
        }
    }
}
