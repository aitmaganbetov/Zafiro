using System;
using System.Media;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Threading;

namespace ZafiroAgent.Views
{
    /// <summary>
    /// Предупреждение о конце сессии поверх игр (10, 5, 3, 1 минута и «Истекло время сессии»):
    /// окно в правом нижнем углу без фокуса — игра не сворачивается — и звук Windows.
    /// </summary>
    public sealed class SessionTimeNotice : Window
    {
        private static SessionTimeNotice? _current;
        private readonly DispatcherTimer _closeTimer;

        public static void Show(string text, bool final)
        {
            _current?.Close();
            var notice = new SessionTimeNotice(text, final);
            _current = notice;
            notice.Show();
            try
            {
                (final ? SystemSounds.Hand : SystemSounds.Exclamation).Play();
            }
            catch (Exception ex)
            {
                Services.SafeLog.Write("[Notice] sound failed: " + ex.GetType().Name);
            }
        }

        private SessionTimeNotice(string text, bool final)
        {
            WindowStyle = WindowStyle.None;
            AllowsTransparency = true;
            Background = Brushes.Transparent;
            ResizeMode = ResizeMode.NoResize;
            SizeToContent = SizeToContent.WidthAndHeight;
            Topmost = true;
            ShowInTaskbar = false;
            ShowActivated = false;
            Focusable = false;
            Content = new Border
            {
                Background = new SolidColorBrush(Color.FromArgb(0xEE, 0x1B, 0x19, 0x25)),
                BorderBrush = new SolidColorBrush(final ? Color.FromRgb(0xD9, 0x00, 0x58) : Color.FromRgb(0x75, 0x54, 0xC8)),
                BorderThickness = new Thickness(2),
                CornerRadius = new CornerRadius(12),
                Padding = new Thickness(22, 16, 22, 16),
                Child = new TextBlock
                {
                    Text = text,
                    Foreground = Brushes.White,
                    FontFamily = new FontFamily("Segoe UI"),
                    FontSize = 20,
                    FontWeight = FontWeights.SemiBold,
                    MaxWidth = 440,
                    TextWrapping = TextWrapping.Wrap
                }
            };

            Loaded += (_, _) =>
            {
                var area = SystemParameters.WorkArea;
                Left = area.Right - ActualWidth - 24;
                Top = area.Bottom - ActualHeight - 24;
            };
            MouseLeftButtonUp += (_, _) => Close();
            _closeTimer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(final ? 10 : 7) };
            _closeTimer.Tick += (_, _) => Close();
            _closeTimer.Start();
            Closed += (_, _) =>
            {
                _closeTimer.Stop();
                if (ReferenceEquals(_current, this)) _current = null;
            };
        }
    }
}
