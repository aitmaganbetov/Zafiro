using System;
using System.Collections.Generic;
using System.ComponentModel;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Runtime.InteropServices;
using System.Security.Principal;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using ZafiroAgent.Services;

namespace ZafiroAgent.Service
{
    /// <summary>
    /// Следит, чтобы в сеансе клиента у консоли всегда работал агент: закрытый или убитый
    /// агент запускается снова через несколько секунд. Агентом считается только процесс из
    /// папки установки, который каждые несколько секунд сообщает службе, что жив: программа
    /// с тем же именем или приостановленный агент не подходят — такой агент перезапускается.
    /// Учётные записи администраторов не трогает (обслуживание ПК), если при установке не выбран
    /// запуск и в них (клуб, где ПК входит в Windows под администратором), а после выхода сотрудника
    /// по PIN-коду ждёт до выхода из Windows, но не дольше времени обслуживания из настроек клуба
    /// (на сервере): сотрудник мог уйти, не выйдя.
    /// </summary>
    public sealed class Watchdog
    {
        private const uint NoSession = 0xFFFFFFFF;
        private static readonly TimeSpan CheckInterval = TimeSpan.FromSeconds(3);

        /// <summary>Агент сообщает о себе каждые 5 с; молчание дольше этого — агент завис или приостановлен.</summary>
        public static readonly TimeSpan AliveTimeout = TimeSpan.FromSeconds(15);

        /// <summary>Время на запуск агента до первого сообщения.</summary>
        public static readonly TimeSpan StartupGrace = TimeSpan.FromSeconds(20);

        /// <summary>Время обслуживания, если сервер его не прислал.</summary>
        public static readonly TimeSpan DefaultStaffPause = TimeSpan.FromMinutes(15);

        private readonly string _agentExe;
        private readonly bool _launchForAdministrators;
        private readonly object _gate = new();
        private readonly Dictionary<uint, DateTime> _paused = new();
        private readonly Dictionary<uint, (int Failures, DateTime NextAttemptUtc)> _launches = new();
        private readonly Dictionary<uint, DateTime> _alive = new();

        public Watchdog(string agentExe, bool launchForAdministrators = false)
        {
            _agentExe = agentExe;
            _launchForAdministrators = launchForAdministrators;
        }

        /// <summary>Сотрудник закрыл агент по PIN-коду: в этом сеансе агент не запускается duration.</summary>
        public void Pause(uint session, TimeSpan duration)
        {
            lock (_gate) _paused[session] = DateTime.UtcNow + duration;
        }

        /// <summary>Агент (процесс pid) сообщил, что работает. Учитывается, только если это exe из папки установки.</summary>
        public void Alive(uint session, uint pid)
        {
            if (!IsAgentImage(pid)) return;
            lock (_gate) _alive[session] = DateTime.UtcNow;
        }

        /// <summary>Агент молчит слишком долго: завис, приостановлен или это не агент.</summary>
        public static bool IsUnresponsive(DateTime? lastAliveUtc, DateTime newestStartUtc, DateTime nowUtc)
        {
            if (nowUtc - newestStartUtc < StartupGrace) return false;
            return lastAliveUtc is not { } last || nowUtc - last > AliveTimeout;
        }

        /// <summary>Вход или выход из Windows, либо агент запущен вручную: наблюдение снова включено.</summary>
        public void Resume(uint session)
        {
            lock (_gate)
            {
                _paused.Remove(session);
                _launches.Remove(session);
                _alive.Remove(session);
            }
        }

        /// <summary>Задержка перед следующим запуском, если агент сразу закрывается: 3 с, 6 с, 12 с … до 1 мин.</summary>
        public static TimeSpan RetryDelay(int failures) =>
            TimeSpan.FromSeconds(Math.Min(60, 3 * Math.Pow(2, Math.Clamp(failures - 1, 0, 5))));

        public async Task RunAsync(CancellationToken cancellationToken)
        {
            while (!cancellationToken.IsCancellationRequested)
            {
                try
                {
                    Check();
                }
                catch (Exception ex)
                {
                    SafeLog.Write("[Watchdog] check failed: " + ex.Message);
                }

                try
                {
                    await Task.Delay(CheckInterval, cancellationToken).ConfigureAwait(false);
                }
                catch (OperationCanceledException)
                {
                    return;
                }
            }
        }

        private void Check()
        {
            var session = WTSGetActiveConsoleSessionId();
            if (session == NoSession || session == 0) return;

            var now = DateTime.UtcNow;
            var agents = AgentProcesses(session);
            try
            {
                lock (_gate)
                {
                    if (_paused.TryGetValue(session, out var pausedUntil))
                    {
                        if (now < pausedUntil) return;
                        _paused.Remove(session);
                        SafeLog.Write("[Watchdog] staff pause is over in session " + session);
                    }

                    if (agents.Count > 0)
                    {
                        var newestStart = agents.Max(p => p.StartTime.ToUniversalTime());
                        DateTime? lastAlive = _alive.TryGetValue(session, out var at) ? at : null;
                        if (!IsUnresponsive(lastAlive, newestStart, now))
                        {
                            _launches.Remove(session);
                            return;
                        }

                        SafeLog.Write("[Watchdog] agent is not responding in session " + session + ", restarting it");
                        foreach (var agent in agents) Kill(agent);
                        _alive.Remove(session);
                    }
                    else if (_launches.TryGetValue(session, out var last) && now < last.NextAttemptUtc)
                    {
                        return;
                    }
                }
            }
            finally
            {
                foreach (var agent in agents) agent.Dispose();
            }

            if (!WTSQueryUserToken(session, out var userToken)) return; // никто не вошёл
            try
            {
                if (!_launchForAdministrators && IsAdministrator(userToken)) return;
                Launch(userToken);
                SafeLog.Write("[Watchdog] agent started in session " + session);
            }
            catch (Win32Exception ex)
            {
                SafeLog.Write("[Watchdog] could not start the agent: " + ex.Message);
            }
            finally
            {
                CloseHandle(userToken);
                lock (_gate)
                {
                    var failures = _launches.TryGetValue(session, out var last) ? last.Failures + 1 : 1;
                    _launches[session] = (failures, now + RetryDelay(failures));
                }
            }
        }

        // Процессы агента в сеансе: имя может взять любая программа, поэтому сверяется путь к exe.
        private List<Process> AgentProcesses(uint session)
        {
            var result = new List<Process>();
            foreach (var process in Process.GetProcessesByName(Path.GetFileNameWithoutExtension(_agentExe)))
            {
                if ((uint)process.SessionId == session && IsAgentImage((uint)process.Id)) result.Add(process);
                else process.Dispose();
            }

            return result;
        }

        private bool IsAgentImage(uint pid)
        {
            var handle = OpenProcess(ProcessQueryLimitedInformation, false, pid);
            if (handle == IntPtr.Zero) return false;
            try
            {
                var path = new StringBuilder(1024);
                var length = path.Capacity;
                return QueryFullProcessImageName(handle, 0, path, ref length)
                    && string.Equals(Path.GetFullPath(path.ToString()), Path.GetFullPath(_agentExe), StringComparison.OrdinalIgnoreCase);
            }
            finally
            {
                CloseHandle(handle);
            }
        }

        private static void Kill(Process process)
        {
            try
            {
                process.Kill();
            }
            catch (Exception ex) when (ex is Win32Exception or InvalidOperationException)
            {
                SafeLog.Write("[Watchdog] could not stop the agent: " + ex.Message);
            }
        }

        // С UAC токен администратора разделён (Limited/Full); без UAC — обычный токен в группе Администраторы.
        private static bool IsAdministrator(IntPtr token)
        {
            if (GetTokenInformation(token, TokenElevationType, out var type, sizeof(int), out _) && type != ElevationTypeDefault)
                return true;

            using var identity = new WindowsIdentity(token);
            return new WindowsPrincipal(identity).IsInRole(WindowsBuiltInRole.Administrator);
        }

        private void Launch(IntPtr userToken)
        {
            if (!DuplicateTokenEx(userToken, MaximumAllowed, IntPtr.Zero, SecurityImpersonation, TokenPrimary, out var primary))
                throw new Win32Exception(Marshal.GetLastWin32Error());

            var environment = IntPtr.Zero;
            try
            {
                if (!CreateEnvironmentBlock(out environment, primary, false))
                    throw new Win32Exception(Marshal.GetLastWin32Error());

                var startup = new StartupInfo { cb = Marshal.SizeOf<StartupInfo>(), lpDesktop = @"winsta0\default" };
                var commandLine = new StringBuilder($"\"{_agentExe}\" --watchdog");
                if (!CreateProcessAsUser(primary, _agentExe, commandLine, IntPtr.Zero, IntPtr.Zero, false, CreateUnicodeEnvironment,
                        environment, Path.GetDirectoryName(_agentExe), ref startup, out var process))
                    throw new Win32Exception(Marshal.GetLastWin32Error());

                CloseHandle(process.hThread);
                CloseHandle(process.hProcess);
            }
            finally
            {
                if (environment != IntPtr.Zero) DestroyEnvironmentBlock(environment);
                CloseHandle(primary);
            }
        }

        private const int TokenElevationType = 18;
        private const int ElevationTypeDefault = 1;
        private const uint MaximumAllowed = 0x02000000;
        private const int SecurityImpersonation = 2;
        private const int TokenPrimary = 1;
        private const uint CreateUnicodeEnvironment = 0x00000400;
        private const uint ProcessQueryLimitedInformation = 0x1000;

        [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
        private struct StartupInfo
        {
            public int cb;
            public string? lpReserved;
            public string? lpDesktop;
            public string? lpTitle;
            public int dwX, dwY, dwXSize, dwYSize, dwXCountChars, dwYCountChars, dwFillAttribute, dwFlags;
            public short wShowWindow, cbReserved2;
            public IntPtr lpReserved2, hStdInput, hStdOutput, hStdError;
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct ProcessInformation
        {
            public IntPtr hProcess, hThread;
            public int dwProcessId, dwThreadId;
        }

        [DllImport("kernel32.dll")]
        private static extern uint WTSGetActiveConsoleSessionId();

        [DllImport("wtsapi32.dll", SetLastError = true)]
        private static extern bool WTSQueryUserToken(uint sessionId, out IntPtr token);

        [DllImport("advapi32.dll", SetLastError = true)]
        private static extern bool GetTokenInformation(IntPtr token, int infoClass, out int info, int length, out int returnLength);

        [DllImport("advapi32.dll", SetLastError = true)]
        private static extern bool DuplicateTokenEx(IntPtr token, uint access, IntPtr attributes, int level, int type, out IntPtr newToken);

        [DllImport("userenv.dll", SetLastError = true)]
        private static extern bool CreateEnvironmentBlock(out IntPtr environment, IntPtr token, bool inherit);

        [DllImport("userenv.dll")]
        private static extern bool DestroyEnvironmentBlock(IntPtr environment);

        [DllImport("advapi32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
        private static extern bool CreateProcessAsUser(IntPtr token, string? application, StringBuilder commandLine, IntPtr processAttributes,
            IntPtr threadAttributes, bool inheritHandles, uint flags, IntPtr environment, string? directory, ref StartupInfo startup, out ProcessInformation process);

        [DllImport("kernel32.dll")]
        private static extern bool CloseHandle(IntPtr handle);

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern IntPtr OpenProcess(uint access, bool inheritHandle, uint processId);

        [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
        private static extern bool QueryFullProcessImageName(IntPtr process, int flags, StringBuilder path, ref int size);
    }
}
