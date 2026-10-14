using System;
using Microsoft.Win32;
using ZafiroAgent.Services;

namespace ZafiroAgent.Kiosk
{
    /// <summary>
    /// Настройки Windows для режима киоска (только для текущего пользователя, без прав администратора):
    /// диспетчер задач отключён, пока работает агент, и агент запускается при входе в Windows.
    /// </summary>
    public static class KioskPolicy
    {
        private const string PolicyKey = @"Software\Microsoft\Windows\CurrentVersion\Policies\System";
        private const string RunKey = @"Software\Microsoft\Windows\CurrentVersion\Run";
        private const string RunValue = "ZafiroAgent";

        public static void DisableTaskManager() => Try("disable task manager", () =>
        {
            using var key = Registry.CurrentUser.CreateSubKey(PolicyKey);
            key.SetValue("DisableTaskMgr", 1, RegistryValueKind.DWord);
        });

        // Возвращает диспетчер задач: при выходе сотрудника по PIN-коду.
        public static void EnableTaskManager() => Try("enable task manager", () =>
        {
            using var key = Registry.CurrentUser.OpenSubKey(PolicyKey, writable: true);
            key?.DeleteValue("DisableTaskMgr", throwOnMissingValue: false);
        });

        public static void EnsureAutostart() => Try("register autostart", () =>
        {
            var exe = Environment.ProcessPath;
            if (string.IsNullOrEmpty(exe)) return;
            using var key = Registry.CurrentUser.CreateSubKey(RunKey);
            key.SetValue(RunValue, $"\"{exe}\"");
        });

        private static void Try(string action, Action change)
        {
            try
            {
                change();
            }
            catch (Exception ex)
            {
                SafeLog.Write($"[Kiosk] could not {action}: {ex.Message}");
            }
        }
    }
}
