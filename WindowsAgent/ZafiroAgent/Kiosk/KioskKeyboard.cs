using System;
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Threading;
using ZafiroAgent.Services;

namespace ZafiroAgent.Kiosk
{
    /// <summary>
    /// Системный перехват клавиатуры (WH_KEYBOARD_LL) для режима киоска.
    /// Ctrl+Alt+P всегда открывает окно PIN-кода сотрудника. Пока ПК не оплачен (Strict),
    /// блокируются клавиши, которые выводят из агента: Win, Alt+Tab, Alt+Esc, Ctrl+Esc,
    /// Alt+F4, Alt+Пробел, клавиша меню. Всегда блокируются Ctrl+Shift+Esc (диспетчер задач),
    /// Win+Tab и Win+Ctrl+D/←/→/F4 (виртуальные рабочие столы: на другом столе агента не видно).
    /// Ctrl+Alt+Del перехватить нельзя: это ограничение Windows.
    /// Перехват работает в своём потоке: если окно агента подвиснет, Windows его не снимет.
    /// </summary>
    public sealed class KioskKeyboard : IDisposable
    {
        public const int VkTab = 0x09, VkEscape = 0x1B, VkSpace = 0x20, VkP = 0x50, VkF4 = 0x73;
        public const int VkLWin = 0x5B, VkRWin = 0x5C, VkApps = 0x5D;
        public const int VkLeft = 0x25, VkRight = 0x27, VkD = 0x44;

        private const int WhKeyboardLl = 13;
        private const int WmKeyDown = 0x0100, WmSysKeyDown = 0x0104;
        private const int LlkhfAltDown = 0x20;
        private const int VkShift = 0x10, VkControl = 0x11;
        private static readonly IntPtr Blocked = new(1);

        private const int WmQuit = 0x0012;

        private readonly LowLevelKeyboardProc _callback;
        private IntPtr _hook = IntPtr.Zero;
        private Thread? _thread;
        private uint _threadId;
        private volatile bool _enabled;
        private volatile bool _strict = true;

        /// <summary>Режим киоска включён (ПК настроен сотрудником).</summary>
        public bool Enabled { get => _enabled; set => _enabled = value; }

        /// <summary>Нет оплаченной сессии: блокируются все клавиши выхода из агента.</summary>
        public bool Strict { get => _strict; set => _strict = value; }

        public event Action? StaffShortcut;

        public KioskKeyboard()
        {
            _callback = Callback; // делегат хранится в поле, иначе его соберёт GC
        }

        /// <summary>Ставит перехват в отдельном потоке с собственным циклом сообщений. StaffShortcut вызывается из этого потока.</summary>
        public void Install()
        {
            if (_thread is not null) return;
            using var ready = new ManualResetEventSlim();
            _thread = new Thread(() =>
            {
                _threadId = GetCurrentThreadId();
                using (var module = Process.GetCurrentProcess().MainModule)
                    _hook = SetWindowsHookEx(WhKeyboardLl, _callback, GetModuleHandle(module?.ModuleName), 0);
                if (_hook == IntPtr.Zero) SafeLog.Write("[Kiosk] keyboard hook failed: " + Marshal.GetLastWin32Error());
                ready.Set();

                while (GetMessage(out var message, IntPtr.Zero, 0, 0) > 0)
                {
                    TranslateMessage(ref message);
                    DispatchMessage(ref message);
                }

                if (_hook != IntPtr.Zero) UnhookWindowsHookEx(_hook);
                _hook = IntPtr.Zero;
            })
            {
                IsBackground = true,
                Priority = ThreadPriority.Highest,
                Name = "Kiosk keyboard"
            };
            _thread.Start();
            ready.Wait(TimeSpan.FromSeconds(5));
        }

        /// <summary>Решение о блокировке, без обращения к Windows (проверяется тестами).</summary>
        public static bool ShouldBlock(int vk, bool alt, bool ctrl, bool shift, bool strict, bool win = false)
        {
            if (ctrl && shift && vk == VkEscape) return true;
            if (win && (vk == VkTab || (ctrl && vk is VkD or VkLeft or VkRight or VkF4))) return true;
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
                var win = ((GetAsyncKeyState(VkLWin) | GetAsyncKeyState(VkRWin)) & 0x8000) != 0;

                if (IsStaffShortcut(vk, alt, ctrl))
                {
                    if (down) StaffShortcut?.Invoke();
                    return Blocked;
                }

                if (_enabled && ShouldBlock(vk, alt, ctrl, shift, _strict, win)) return Blocked;
            }

            return CallNextHookEx(_hook, nCode, wParam, lParam);
        }

        public void Dispose()
        {
            if (_thread is null) return;
            PostThreadMessage(_threadId, WmQuit, IntPtr.Zero, IntPtr.Zero);
            _thread.Join(TimeSpan.FromSeconds(2));
            _thread = null;
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

        [StructLayout(LayoutKind.Sequential)]
        private struct Msg
        {
            public IntPtr Hwnd;
            public uint Message;
            public IntPtr WParam, LParam;
            public uint Time;
            public int X, Y;
        }

        [DllImport("user32.dll")]
        private static extern int GetMessage(out Msg message, IntPtr window, uint filterMin, uint filterMax);

        [DllImport("user32.dll")]
        private static extern bool TranslateMessage(ref Msg message);

        [DllImport("user32.dll")]
        private static extern IntPtr DispatchMessage(ref Msg message);

        [DllImport("user32.dll")]
        private static extern bool PostThreadMessage(uint threadId, uint message, IntPtr wParam, IntPtr lParam);

        [DllImport("kernel32.dll")]
        private static extern uint GetCurrentThreadId();
    }
}
