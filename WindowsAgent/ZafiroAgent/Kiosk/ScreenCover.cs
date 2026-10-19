using System;
using System.Collections.Generic;
using System.IO;
using System.Net.Http;
using System.Runtime.InteropServices;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Interop;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using Microsoft.Win32;
using ZafiroAgent.Services;

namespace ZafiroAgent.Kiosk
{
    /// <summary>
    /// Без оплаченной сессии агент закрывает все мониторы: на мониторе окна агента — само окно,
    /// на остальных — чёрные окна поверх всех с названием и логотипом клуба. Подключённый позже
    /// монитор закрывается сразу. Логотип не загрузился — окно всё равно чёрное и закрывает монитор.
    /// </summary>
    public sealed class ScreenCover : IDisposable
    {
        private const long MaxLogoBytes = 2 * 1024 * 1024;
        private const int MaxLogoPixels = 1024;
        private static readonly TimeSpan LogoRetryDelay = TimeSpan.FromMinutes(10);
        private static readonly Brush NameBrush = Freeze(new SolidColorBrush(Color.FromRgb(0xE6, 0xE8, 0xEE)));

        private readonly List<Window> _covers = new();
        private Window? _keep;
        private bool _shown;
        private string _clubName = string.Empty;
        private ImageSource? _logo;
        private string? _logoUrl;
        private string? _failedLogoUrl;
        private DateTime _logoRetryAtUtc;

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

        /// <summary>
        /// Название и логотип клуба на закрытых мониторах (вызывать из UI-потока).
        /// Логотип скачивается только при смене ссылки; null — логотипа нет.
        /// </summary>
        public void SetBranding(string? clubName, string? logoUrl)
        {
            var name = clubName?.Trim() ?? string.Empty;
            if (name.Length > 0 && name != _clubName)
            {
                _clubName = name;
                Refresh();
            }

            if (logoUrl == _logoUrl) return;
            if (logoUrl is not null && logoUrl == _failedLogoUrl && DateTime.UtcNow < _logoRetryAtUtc) return;

            _logoUrl = logoUrl;
            if (logoUrl is null)
            {
                _logo = null;
                Refresh();
                return;
            }

            _ = LoadLogoAsync(logoUrl);
        }

        private async Task LoadLogoAsync(string url)
        {
            ImageSource? logo = null;
            try
            {
                logo = await Task.Run(() => DownloadLogoAsync(url));
            }
            catch (Exception ex)
            {
                SafeLog.Write("[Kiosk] could not load the club logo: " + ex.Message);
            }

            // Пока логотип грузился, ссылка могла смениться: устаревший результат не показываем.
            if (url != _logoUrl) return;
            if (logo is null)
            {
                // Повтор не раньше чем через 10 минут, а не на каждый heartbeat.
                _logoUrl = null;
                _failedLogoUrl = url;
                _logoRetryAtUtc = DateTime.UtcNow + LogoRetryDelay;
                return;
            }

            _logo = logo;
            Refresh();
        }

        /// <summary>Только HTTPS на хост Zafiro, без редиректов, не больше 2 МБ; картинка уменьшается до 1024 px.</summary>
        private static Task<ImageSource?> DownloadLogoAsync(string url) =>
            Infrastructure.RemoteImage.DownloadAsync(url, MaxLogoBytes, MaxLogoPixels);

        private void Refresh()
        {
            foreach (var cover in _covers) cover.Content = Branding();
        }

        private UIElement Branding()
        {
            var panel = new StackPanel
            {
                HorizontalAlignment = HorizontalAlignment.Center,
                VerticalAlignment = VerticalAlignment.Center,
                Margin = new Thickness(48),
                IsHitTestVisible = false
            };
            if (_logo is not null)
                panel.Children.Add(new Image { Source = _logo, Stretch = Stretch.Uniform, MaxWidth = 480, MaxHeight = 320, Margin = new Thickness(0, 0, 0, 24) });
            if (_clubName.Length > 0)
                panel.Children.Add(new TextBlock
                {
                    Text = _clubName,
                    Foreground = NameBrush,
                    FontFamily = new FontFamily("Segoe UI"),
                    FontSize = 40,
                    FontWeight = FontWeights.SemiBold,
                    TextAlignment = TextAlignment.Center,
                    TextWrapping = TextWrapping.Wrap,
                    MaxWidth = 900
                });
            return panel;
        }

        private static Brush Freeze(Brush brush)
        {
            brush.Freeze();
            return brush;
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
                Height = 1,
                Content = Branding()
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
