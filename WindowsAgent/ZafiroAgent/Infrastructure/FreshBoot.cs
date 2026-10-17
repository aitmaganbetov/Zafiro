using System;
using System.Threading;
using Microsoft.Win32;
using ZafiroAgent.Services;

namespace ZafiroAgent.Infrastructure
{
    /// <summary>
    /// Первый запуск агента после загрузки Windows (или нового входа в учётную запись). Метка —
    /// «летучий» раздел реестра HKCU: Windows удаляет его при выходе из учётной записи и при
    /// перезагрузке, а перезапуск агента службой его не трогает. Сервер по этому сигналу
    /// сбрасывает гостевую сессию ПК.
    /// </summary>
    public static class FreshBoot
    {
        // Прямо в Software: у «летучего» раздела все вложенные разделы тоже «летучие».
        private const string MarkerKey = @"Software\ZafiroAgentRun";

        private static int _pending;

        /// <summary>Вызывается один раз при запуске агента.</summary>
        public static void Detect()
        {
            try
            {
                using (var existing = Registry.CurrentUser.OpenSubKey(MarkerKey))
                {
                    if (existing is not null) return;
                }

                using var created = Registry.CurrentUser.CreateSubKey(MarkerKey, false, RegistryOptions.Volatile);
                Interlocked.Exchange(ref _pending, 1);
                SafeLog.Write("[Boot] first agent start since Windows started");
            }
            catch (Exception ex) when (ex is UnauthorizedAccessException or System.Security.SecurityException or System.IO.IOException)
            {
                SafeLog.Write("[Boot] could not check the start marker: " + ex.GetType().Name);
            }
        }

        /// <summary>Сервер ещё не знает о новой загрузке.</summary>
        public static bool Pending => Volatile.Read(ref _pending) == 1;

        /// <summary>Сервер принял сигнал: больше не отправлять.</summary>
        public static void Reported() => Interlocked.Exchange(ref _pending, 0);
    }
}
