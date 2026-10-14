using System;
using Microsoft.Win32;
using ZafiroAgent.Services;

namespace ZafiroAgent.Kiosk
{
    /// <summary>
    /// Настройки Windows для режима киоска (только для текущего пользователя, без прав администратора):
    /// диспетчер задач отключён, пока работает агент, и агент запускается при входе в Windows
    /// (без службы «Zafiro Agent»; со службой агент запускает она).
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

        // Возвращает диспетчер задач: при выходе сотрудника по PIN-коду. Со службой запрет
        // ставит Protect-ClubPc.ps1 для учётной записи клиента, и агент его не снимает.
        public static void EnableTaskManager() => Try("enable task manager", () =>
        {
            if (DeviceVaultClient.IsServiceInstalled()) return;
            using var key = Registry.CurrentUser.OpenSubKey(PolicyKey, writable: true);
            key?.DeleteValue("DisableTaskMgr", throwOnMissingValue: false);
        });

        // Если установлена служба, агент запускает она: своя запись автозапуска дала бы второй запуск.
        public static void EnsureAutostart() => Try("register autostart", () =>
        {
            var exe = Environment.ProcessPath;
            if (string.IsNullOrEmpty(exe)) return;
            using var key = Registry.CurrentUser.CreateSubKey(RunKey);
            if (DeviceVaultClient.IsServiceInstalled())
                key.DeleteValue(RunValue, throwOnMissingValue: false);
            else
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
