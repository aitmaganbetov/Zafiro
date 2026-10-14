using System;
using System.Collections.Generic;
using System.Runtime.InteropServices;
using System.Windows;
using System.Windows.Interop;
using System.Windows.Media;
using Microsoft.Win32;
using ZafiroAgent.Services;

namespace ZafiroAgent.Kiosk
{
    /// <summary>
    /// Без оплаченной сессии агент закрывает все мониторы: на мониторе окна агента — само окно,
    /// на остальных — чёрные окна поверх всех. Подключённый позже монитор закрывается сразу.
    /// </summary>
    public sealed class ScreenCover : IDisposable
    {
        private readonly List<Window> _covers = new();
        private Window? _keep;
        private bool _shown;

        public ScreenCover()
        {
            SystemEvents.DisplaySettingsChanged += OnDisplaysChanged;
        }

        /// <summary>show = true: закрыть все мониторы, кроме монитора окна keep; false — убрать.</summary>
        public void Update(Window? keep, bool show)
        {
            _keep = keep;
            _shown = show;
            Rebuild();
        }

        private void OnDisplaysChanged(object? sender, EventArgs e) =>
            Application.Current?.Dispatcher.BeginInvoke(Rebuild);

        private void Rebuild()
        {
            Close();
            if (!_shown) return;

            var keepMonitor = _keep is null ? IntPtr.Zero : MonitorFromWindow(new WindowInteropHelper(_keep).EnsureHandle(), MonitorDefaultToNearest);
            foreach (var (monitor, bounds) in Monitors())
            {
                if (monitor == keepMonitor) continue;
                try
                {
                    _covers.Add(Cover(bounds));
                }
                catch (Exception ex)
                {
                    SafeLog.Write("[Kiosk] could not cover a monitor: " + ex.Message);
                }
            }
        }

        private Window Cover(Rect bounds)
        {
            var window = new Window
            {
                WindowStyle = WindowStyle.None,
                ResizeMode = ResizeMode.NoResize,
                ShowInTaskbar = false,
                ShowActivated = false,
                Topmost = true,
                Background = Brushes.Black,
                Width = 1,
                Height = 1
            };
            window.Closing += (_, e) => e.Cancel = _covers.Contains(window); // Alt+F4 по чёрному окну
            window.MouseDown += (_, _) => _keep?.Activate();
            var handle = new WindowInteropHelper(window).EnsureHandle();
            window.Show();
            // Координаты монитора — в физических пикселях, поэтому окно ставится через Win32.
            SetWindowPos(handle, HwndTopmost, (int)bounds.X, (int)bounds.Y, (int)bounds.Width, (int)bounds.Height, SwpNoActivate | SwpShowWindow);
            return window;
        }

        private void Close()
        {
            var covers = _covers.ToArray();
            _covers.Clear();
            foreach (var cover in covers) cover.Close();
        }

        public void Dispose()
        {
            SystemEvents.DisplaySettingsChanged -= OnDisplaysChanged;
            _shown = false;
            Close();
        }

        private static List<(IntPtr Monitor, Rect Bounds)> Monitors()
        {
            var result = new List<(IntPtr, Rect)>();
            EnumDisplayMonitors(IntPtr.Zero, IntPtr.Zero, (monitor, _, _, _) =>
            {
                var info = new MonitorInfo { cbSize = Marshal.SizeOf<MonitorInfo>() };
                if (GetMonitorInfo(monitor, ref info))
                {
                    var r = info.rcMonitor;
                    result.Add((monitor, new Rect(r.Left, r.Top, r.Right - r.Left, r.Bottom - r.Top)));
                }

                return true;
            }, IntPtr.Zero);
            return result;
        }

        private const uint MonitorDefaultToNearest = 2;
        private const uint SwpNoActivate = 0x0010;
        private const uint SwpShowWindow = 0x0040;
        private static readonly IntPtr HwndTopmost = new(-1);

        private delegate bool MonitorEnumProc(IntPtr monitor, IntPtr hdc, IntPtr rect, IntPtr data);

        [StructLayout(LayoutKind.Sequential)]
        private struct NativeRect
        {
            public int Left, Top, Right, Bottom;
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct MonitorInfo
        {
            public int cbSize;
            public NativeRect rcMonitor;
            public NativeRect rcWork;
            public uint dwFlags;
        }

        [DllImport("user32.dll")]
        private static extern bool EnumDisplayMonitors(IntPtr hdc, IntPtr clip, MonitorEnumProc callback, IntPtr data);

        [DllImport("user32.dll")]
        private static extern bool GetMonitorInfo(IntPtr monitor, ref MonitorInfo info);

        [DllImport("user32.dll")]
        private static extern IntPtr MonitorFromWindow(IntPtr window, uint flags);

        [DllImport("user32.dll", SetLastError = true)]
        private static extern bool SetWindowPos(IntPtr window, IntPtr insertAfter, int x, int y, int width, int height, uint flags);
    }
}
