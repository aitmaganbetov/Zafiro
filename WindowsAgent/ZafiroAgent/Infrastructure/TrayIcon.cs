using System;
using System.Runtime.InteropServices;
using System.Windows.Controls;
using System.Windows.Controls.Primitives;
using System.Windows.Interop;

namespace ZafiroAgent.Infrastructure
{
    /// <summary>
    /// Значок в области уведомлений через Shell_NotifyIcon, без Windows Forms
    /// (Windows Forms добавлял к агенту около 22 МБ только ради этого значка).
    /// Двойной щелчок — «Открыть», правая кнопка — меню.
    /// </summary>
    public sealed class TrayIcon : IDisposable
    {
        private const int WmApp = 0x8000;
        private const int CallbackMessage = WmApp + 1;
        private const int WmLButtonDblClk = 0x0203;
        private const int WmRButtonUp = 0x0205;
        private const int NimAdd = 0, NimDelete = 2;
        private const int NifMessage = 0x1, NifIcon = 0x2, NifTip = 0x4;
        private const int IdiApplication = 32512;

        private readonly HwndSource _window;
        private readonly int _taskbarCreated;
        private readonly string _tip;
        private readonly ContextMenu _menu = new();
        private bool _added;
        private IntPtr _icon;

        public event Action? Open;

        public TrayIcon(string tip, params (string Text, Action Click)[] menuItems)
        {
            _tip = tip;
            foreach (var (text, click) in menuItems)
            {
                var item = new MenuItem { Header = text };
                item.Click += (_, _) => click();
                _menu.Items.Add(item);
            }

            _menu.Placement = PlacementMode.MousePoint;
            // Невидимое окно только для сообщений от значка.
            _window = new HwndSource(new HwndSourceParameters("ZafiroAgentTray") { Width = 0, Height = 0, WindowStyle = 0 });
            _window.AddHook(WndProc);
            _taskbarCreated = RegisterWindowMessage("TaskbarCreated");
            Add();
        }

        private void Add()
        {
            var data = Data();
            data.uFlags = NifMessage | NifIcon | NifTip;
            data.uCallbackMessage = CallbackMessage;
            // Значок самого exe (логотип Zafiro); если его нет — стандартный значок Windows.
            if (_icon == IntPtr.Zero && Environment.ProcessPath is { } exe) _icon = ExtractIcon(IntPtr.Zero, exe, 0);
            data.hIcon = _icon > 1 ? _icon : LoadIcon(IntPtr.Zero, (IntPtr)IdiApplication);
            data.szTip = _tip;
            _added = Shell_NotifyIcon(NimAdd, ref data);
        }

        private NotifyIconData Data() => new()
        {
            cbSize = Marshal.SizeOf<NotifyIconData>(),
            hWnd = _window.Handle,
            uID = 1,
            szTip = string.Empty,
            szInfo = string.Empty,
            szInfoTitle = string.Empty
        };

        private IntPtr WndProc(IntPtr hwnd, int msg, IntPtr wParam, IntPtr lParam, ref bool handled)
        {
            if (msg == CallbackMessage)
            {
                switch ((int)lParam)
                {
                    case WmLButtonDblClk:
                        Open?.Invoke();
                        handled = true;
                        break;
                    case WmRButtonUp:
                        SetForegroundWindow(hwnd); // иначе меню не закрывается щелчком мимо
                        _menu.IsOpen = true;
                        handled = true;
                        break;
                }
            }
            else if (msg == _taskbarCreated)
            {
                Add(); // проводник перезапустился: значок нужно добавить заново
            }

            return IntPtr.Zero;
        }

        public void Dispose()
        {
            if (_added)
            {
                var data = Data();
                Shell_NotifyIcon(NimDelete, ref data);
                _added = false;
            }

            if (_icon > 1) DestroyIcon(_icon);
            _icon = IntPtr.Zero;
            _window.RemoveHook(WndProc);
            _window.Dispose();
        }

        [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
        private struct NotifyIconData
        {
            public int cbSize;
            public IntPtr hWnd;
            public int uID;
            public int uFlags;
            public int uCallbackMessage;
            public IntPtr hIcon;
            [MarshalAs(UnmanagedType.ByValTStr, SizeConst = 128)] public string szTip;
            public int dwState;
            public int dwStateMask;
            [MarshalAs(UnmanagedType.ByValTStr, SizeConst = 256)] public string szInfo;
            public int uTimeoutOrVersion;
            [MarshalAs(UnmanagedType.ByValTStr, SizeConst = 64)] public string szInfoTitle;
            public int dwInfoFlags;
            public Guid guidItem;
            public IntPtr hBalloonIcon;
        }

        [DllImport("shell32.dll", CharSet = CharSet.Unicode)]
        private static extern bool Shell_NotifyIcon(int dwMessage, ref NotifyIconData lpData);

        [DllImport("user32.dll", CharSet = CharSet.Unicode)]
        private static extern int RegisterWindowMessage(string lpString);

        [DllImport("user32.dll")]
        private static extern IntPtr LoadIcon(IntPtr hInstance, IntPtr lpIconName);

        [DllImport("shell32.dll", CharSet = CharSet.Unicode)]
        private static extern IntPtr ExtractIcon(IntPtr hInstance, string exeFileName, int iconIndex);

        [DllImport("user32.dll")]
        private static extern bool DestroyIcon(IntPtr icon);

        [DllImport("user32.dll")]
        private static extern bool SetForegroundWindow(IntPtr hWnd);
    }
}
