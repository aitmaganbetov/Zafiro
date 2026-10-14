using System;
using System.Diagnostics;
using System.Runtime.InteropServices;

namespace ZafiroAgent.Kiosk
{
    /// <summary>
    /// Системный перехват клавиатуры (WH_KEYBOARD_LL) для режима киоска.
    /// Ctrl+Alt+P всегда открывает окно PIN-кода сотрудника. Пока ПК не оплачен (Strict),
    /// блокируются клавиши, которые выводят из агента: Win, Alt+Tab, Alt+Esc, Ctrl+Esc,
    /// Alt+F4, Alt+Пробел, клавиша меню. Ctrl+Shift+Esc (диспетчер задач) блокируется всегда.
    /// Ctrl+Alt+Del перехватить нельзя: это ограничение Windows.
    /// </summary>
    public sealed class KioskKeyboard : IDisposable
    {
        public const int VkTab = 0x09, VkEscape = 0x1B, VkSpace = 0x20, VkP = 0x50, VkF4 = 0x73;
        public const int VkLWin = 0x5B, VkRWin = 0x5C, VkApps = 0x5D;

        private const int WhKeyboardLl = 13;
        private const int WmKeyDown = 0x0100, WmSysKeyDown = 0x0104;
        private const int LlkhfAltDown = 0x20;
        private const int VkShift = 0x10, VkControl = 0x11;
        private static readonly IntPtr Blocked = new(1);

        private readonly LowLevelKeyboardProc _callback;
        private IntPtr _hook = IntPtr.Zero;

        /// <summary>Режим киоска включён (ПК настроен сотрудником).</summary>
        public bool Enabled { get; set; }

        /// <summary>Нет оплаченной сессии: блокируются все клавиши выхода из агента.</summary>
        public bool Strict { get; set; } = true;

        public event Action? StaffShortcut;

        public KioskKeyboard()
        {
            _callback = Callback; // делегат хранится в поле, иначе его соберёт GC
        }

        public void Install()
        {
            if (_hook != IntPtr.Zero) return;
            using var module = Process.GetCurrentProcess().MainModule;
            _hook = SetWindowsHookEx(WhKeyboardLl, _callback, GetModuleHandle(module?.ModuleName), 0);
        }

        /// <summary>Решение о блокировке, без обращения к Windows (проверяется тестами).</summary>
        public static bool ShouldBlock(int vk, bool alt, bool ctrl, bool shift, bool strict)
        {
            if (ctrl && shift && vk == VkEscape) return true;
            if (!strict) return false;

            return vk is VkLWin or VkRWin or VkApps
                || (alt && vk is VkTab or VkEscape or VkF4 or VkSpace)
                || (ctrl && vk == VkEscape);
        }

        public static bool IsStaffShortcut(int vk, bool alt, bool ctrl) => ctrl && alt && vk == VkP;

        private IntPtr Callback(int nCode, IntPtr wParam, IntPtr lParam)
        {
            if (nCode >= 0)
            {
                var info = Marshal.PtrToStructure<KbdLlHookStruct>(lParam);
                var down = (int)wParam is WmKeyDown or WmSysKeyDown;
                var vk = (int)info.VkCode;
                var alt = (info.Flags & LlkhfAltDown) != 0;
                var ctrl = (GetAsyncKeyState(VkControl) & 0x8000) != 0;
                var shift = (GetAsyncKeyState(VkShift) & 0x8000) != 0;

                if (IsStaffShortcut(vk, alt, ctrl))
                {
                    if (down) StaffShortcut?.Invoke();
                    return Blocked;
                }

                if (Enabled && ShouldBlock(vk, alt, ctrl, shift, Strict)) return Blocked;
            }

            return CallNextHookEx(_hook, nCode, wParam, lParam);
        }

        public void Dispose()
        {
            if (_hook == IntPtr.Zero) return;
            UnhookWindowsHookEx(_hook);
            _hook = IntPtr.Zero;
        }

        private delegate IntPtr LowLevelKeyboardProc(int nCode, IntPtr wParam, IntPtr lParam);

        [StructLayout(LayoutKind.Sequential)]
        private struct KbdLlHookStruct
        {
            public uint VkCode;
            public uint ScanCode;
            public uint Flags;
            public uint Time;
            public IntPtr ExtraInfo;
        }

        [DllImport("user32.dll", SetLastError = true)]
        private static extern IntPtr SetWindowsHookEx(int idHook, LowLevelKeyboardProc lpfn, IntPtr hMod, uint dwThreadId);

        [DllImport("user32.dll", SetLastError = true)]
        private static extern bool UnhookWindowsHookEx(IntPtr hhk);

        [DllImport("user32.dll")]
        private static extern IntPtr CallNextHookEx(IntPtr hhk, int nCode, IntPtr wParam, IntPtr lParam);

        [DllImport("user32.dll")]
        private static extern short GetAsyncKeyState(int vKey);

        [DllImport("kernel32.dll", CharSet = CharSet.Unicode)]
        private static extern IntPtr GetModuleHandle(string? lpModuleName);
    }
}
