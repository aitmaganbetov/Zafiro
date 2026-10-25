using System;
using System.Media;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Threading;
using ZafiroAgent.Localization;

namespace ZafiroAgent.Views
{
    /// <summary>
    /// Сообщение сотрудника с карты клуба: окно в правом верхнем углу поверх игр и экрана входа,
    /// без фокуса — игра не сворачивается. Закрывается щелчком или через 30 секунд.
    /// </summary>
    public sealed class StaffMessageNotice : Window
    {
        private static StaffMessageNotice? _current;
        private readonly DispatcherTimer _closeTimer;

        public static void Show(string text)
        {
            _current?.Close();
            var notice = new StaffMessageNotice(text);
            _current = notice;
            notice.Show();
            try
            {
                SystemSounds.Asterisk.Play();
            }
            catch (Exception ex)
            {
                Services.SafeLog.Write("[Message] sound failed: " + ex.GetType().Name);
            }
        }

        private StaffMessageNotice(string text)
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

            var content = new StackPanel();
            content.Children.Add(new TextBlock
            {
                Text = L.T("Сообщение администратора"),
                Foreground = new SolidColorBrush(Color.FromRgb(0x67, 0xE8, 0xF9)),
                FontFamily = new FontFamily("Segoe UI"),
                FontSize = 14,
                FontWeight = FontWeights.SemiBold,
                Margin = new Thickness(0, 0, 0, 6)
            });
            content.Children.Add(new TextBlock
            {
                Text = text,
                Foreground = Brushes.White,
                FontFamily = new FontFamily("Segoe UI"),
                FontSize = 20,
                FontWeight = FontWeights.SemiBold,
                MaxWidth = 440,
                TextWrapping = TextWrapping.Wrap
            });
            Content = new Border
            {
                Background = new SolidColorBrush(Color.FromArgb(0xEE, 0x1B, 0x19, 0x25)),
                BorderBrush = new SolidColorBrush(Color.FromRgb(0x0E, 0x74, 0x90)),
                BorderThickness = new Thickness(2),
                CornerRadius = new CornerRadius(12),
                Padding = new Thickness(22, 16, 22, 16),
                Child = content
            };

            Loaded += (_, _) =>
            {
                var area = SystemParameters.WorkArea;
                Left = area.Right - ActualWidth - 24;
                Top = area.Top + 24;
            };
            MouseLeftButtonUp += (_, _) => Close();
            _closeTimer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(30) };
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
