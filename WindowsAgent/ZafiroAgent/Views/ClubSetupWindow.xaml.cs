using System;
using System.Globalization;
using System.IO;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using ZafiroAgent.Models;
using ZafiroAgent.Services;
using ZafiroAgent.Localization;

namespace ZafiroAgent.Views
{
    public partial class ClubSetupWindow : Window
    {
        private enum Mode { Setup, Relogin, Connected }

        private static readonly Brush CurrentLanguageBrush = Freeze(new SolidColorBrush(Color.FromRgb(0x75, 0x54, 0xC8)));
        private static readonly Brush OtherLanguageBrush = Freeze(new SolidColorBrush(Color.FromRgb(0x30, 0x2D, 0x3D)));

        private readonly ClubSetupService _setup;
        private bool _relogin;
        private Mode _mode;
        private string? _connectedNumber;
        private string? _connectedZone;
        private readonly CancellationTokenSource _cts = new();
        private bool _busy;
        private bool _qrRunning;
        private bool _registering;
        private bool _guestWatching;
        private GuestSession? _guest;
        private Window? _guestOverlay;
        private TextBlock? _guestOverlayText;
        private readonly System.Windows.Threading.DispatcherTimer _guestTick = new() { Interval = TimeSpan.FromSeconds(1) };

        public ClubSession? Session { get; private set; }

        public ClubSetupWindow(ClubSetupService setup, string? notice = null)
        {
            InitializeComponent();
            _setup = setup;
            _relogin = setup.IsClubConfigured;
            _mode = _relogin ? Mode.Relogin : Mode.Setup;
            if (_relogin) KeyPanel.Visibility = Visibility.Collapsed;
            else MinimizeButton.Visibility = Visibility.Visible;
            // Киоск до подключения ПК не включён: окно видно на панели задач и разворачивается оттуда.
            MinimizeButton.Click += (_, _) =>
            {
                Topmost = false;
                WindowState = WindowState.Minimized;
            };

            ApplyTexts();
            if (!string.IsNullOrEmpty(notice)) ErrorText.Text = notice;

            SubmitButton.Click += async (_, _) =>
            {
                if (_registering) await RegisterAsync();
                else await SubmitAsync();
            };
            RegisterToggle.Click += (_, _) => SetRegistering(!_registering);
            PhoneBox.TextChanged += (_, _) => FormatPhone();
            InputLanguageButton.Click += (_, _) => NextInputLanguage();
            InputLanguageManager.Current.InputLanguageChanged += OnInputLanguageChanged;
            ShowInputLanguage();
            // QR-код — только на подключённом ПК (у него есть токен устройства).
            Loaded += (_, _) =>
            {
                if (!_relogin) return;
                RegisterToggle.Visibility = Visibility.Visible;
                _ = RunQrLoginAsync();
                _ = RunGuestWatchAsync();
            };
            _guestTick.Tick += (_, _) => ShowGuestTime();
            L.Changed += OnLanguageChanged;
            Closed += (_, _) =>
            {
                _cts.Cancel();
                _guestTick.Stop();
                _guestOverlay?.Close();
                L.Changed -= OnLanguageChanged;
                InputLanguageManager.Current.InputLanguageChanged -= OnInputLanguageChanged;
            };
        }

        private void OnLanguageChanged() => Dispatcher.BeginInvoke(ApplyTexts);

        private void OnInputLanguageChanged(object sender, InputLanguageEventArgs e) => Dispatcher.BeginInvoke(ShowInputLanguage);

        /// <summary>Язык ввода Windows, как его подписывает панель задач: РУС, ENG, ҚАЗ.</summary>
        private void ShowInputLanguage()
        {
            var culture = InputLanguageManager.Current.CurrentInputLanguage ?? CultureInfo.CurrentCulture;
            InputLanguageButton.Content = InputLanguageLabel(culture);
            InputLanguageButton.ToolTip = L.T("Язык ввода: :language. Нажмите, чтобы сменить (или Alt+Shift).", ("language", culture.NativeName));
            System.Windows.Automation.AutomationProperties.SetName(InputLanguageButton, (string)InputLanguageButton.ToolTip);
        }

        public static string InputLanguageLabel(CultureInfo culture) => culture.TwoLetterISOLanguageName switch
        {
            "ru" => "РУС",
            "kk" => "ҚАЗ",
            "en" => "ENG",
            _ => culture.ThreeLetterWindowsLanguageName.ToUpperInvariant()
        };

        private void NextInputLanguage()
        {
            try
            {
                var languages = InputLanguageManager.Current.AvailableInputLanguages?.Cast<CultureInfo>().ToList();
                if (languages is not { Count: > 1 }) return;
                var current = InputLanguageManager.Current.CurrentInputLanguage;
                var index = languages.FindIndex(language => language.Equals(current));
                InputLanguageManager.Current.CurrentInputLanguage = languages[(index + 1) % languages.Count];
            }
            catch (Exception ex)
            {
                SafeLog.Write("[InputLanguage] switch failed: " + ex.Message);
            }

            ShowInputLanguage();
            if (PasswordBox.IsVisible) PasswordBox.Focus();
        }

        private bool _formattingPhone;
        private string _phoneText = string.Empty;

        /// <summary>
        /// Номер по мере ввода — «700 123-45-67»; курсор остаётся после той же цифры, что и до
        /// форматирования (в том числе при вставке и удалении в середине).
        /// </summary>
        private void FormatPhone()
        {
            if (_formattingPhone) return;
            var text = PhoneBox.Text;
            var digits = new string(text.Where(c => c is >= '0' and <= '9').ToArray());
            var allDigits = digits.Length;
            // Номер уже полный и к нему дописали одну цифру: она не вводится (иначе 11 цифр
            // с 7 в начале были бы приняты за вставленный номер с кодом страны). Вставка
            // другого номера целиком поверх старого форматируется как обычно.
            var previous = new string(_phoneText.Where(c => c is >= '0' and <= '9').ToArray());
            var extraDigit = previous.Length == 10 && allDigits == 11
                && Enumerable.Range(0, 11).Any(i => digits.Remove(i, 1) == previous);
            var formatted = extraDigit ? _phoneText : PhoneNumberFormatter.FormatLocal(text);
            PhoneHint.Visibility = text.Length == 0 ? Visibility.Visible : Visibility.Collapsed;
            if (formatted == text)
            {
                _phoneText = formatted;
                return;
            }

            var caret = PhoneBox.CaretIndex;
            var digitsBefore = 0;
            for (var i = 0; i < Math.Min(caret, text.Length); i++)
                if (text[i] is >= '0' and <= '9') digitsBefore++;
            if (extraDigit) digitsBefore = Math.Min(digitsBefore, 10);
            // Вставлен номер целиком с 7 или 8: первая цифра отброшена.
            else if (allDigits == 11 && formatted.Length == 13) digitsBefore = Math.Max(0, digitsBefore - 1);

            var position = 0;
            for (var seen = 0; position < formatted.Length && seen < digitsBefore; position++)
                if (formatted[position] is >= '0' and <= '9') seen++;

            _formattingPhone = true;
            PhoneBox.Text = formatted;
            PhoneBox.CaretIndex = position;
            _formattingPhone = false;
            _phoneText = formatted;
            PhoneHint.Visibility = formatted.Length == 0 ? Visibility.Visible : Visibility.Collapsed;
        }

        /// <summary>Все тексты окна на текущем языке: при смене языка окно не теряет введённые данные.</summary>
        private void ApplyTexts()
        {
            Title = L.T("Подключение к клубу Zafiro");
            var computer = _connectedNumber ?? (string.IsNullOrWhiteSpace(_setup.ComputerNumber) ? _setup.ComputerId : _setup.ComputerNumber);
            var zone = _mode == Mode.Connected ? _connectedZone : _setup.Zone;
            ComputerText.Text = L.T("ПК: :computer", ("computer", string.IsNullOrWhiteSpace(computer) ? L.T("не задан") : computer));
            ZoneText.Text = L.T("Зона: :zone", ("zone", string.IsNullOrWhiteSpace(zone) ? L.T("не задана") : zone));
            (HeaderText.Text, ClubText.Text) = _mode switch
            {
                Mode.Relogin => (L.T("Повторный вход"), L.T("Клуб: :club", ("club", _setup.ClubName))),
                Mode.Connected => (L.T("ПК подключён"), L.T("Клуб: :club · ПК :number. Сотрудник вышел — теперь клиент входит своей учётной записью.", ("club", _setup.ClubName), ("number", _connectedNumber))),
                _ => (L.T("Подключение к клубу"), L.T("Введите ключ установки клуба и данные вашей учётной записи."))
            };
            KeyLabel.Text = L.T("Ключ установки");
            PhoneLabel.Text = L.T("Телефон");
            PasswordLabel.Text = L.T("Пароль");
            SubmitButton.Content = _registering ? L.T("Зарегистрироваться") : L.T("Войти");
            NameLabel.Text = L.T("Имя");
            ConfirmLabel.Text = L.T("Повторите пароль");
            RegisterToggle.Content = _registering ? L.T("Уже есть аккаунт? Войти") : L.T("Нет аккаунта? Зарегистрироваться");
            if (_registering) HeaderText.Text = L.T("Регистрация");
            StaffHint.Text = L.T("Сотрудник клуба: Ctrl+Alt+P — выход из агента по PIN-коду.");
            MinimizeButton.Content = L.T("Свернуть, чтобы скопировать ключ");
            QrTitle.Text = L.T("Вход с телефона");
            QrHint.Text = L.T("Наведите камеру телефона на QR-код, войдите на сайте и подтвердите вход.");
            RenderLanguages();
        }

        private void RenderLanguages()
        {
            LanguagePanel.Children.Clear();
            var languages = L.Languages;
            LanguagePanel.Visibility = languages.Count > 1 ? Visibility.Visible : Visibility.Collapsed;
            foreach (var language in languages)
            {
                var button = new Button
                {
                    Content = ShortName(language.Code),
                    ToolTip = language.Name,
                    MinWidth = 44,
                    Height = 30,
                    Margin = new Thickness(4, 0, 0, 0),
                    Padding = new Thickness(10, 0, 10, 0),
                    FontSize = 12,
                    Background = language.Code == L.Current ? CurrentLanguageBrush : OtherLanguageBrush
                };
                System.Windows.Automation.AutomationProperties.SetName(button, language.Name);
                button.Click += (_, _) =>
                {
                    if (_busy) return;
                    ErrorText.Text = string.Empty;
                    L.SetUserLocale(language.Code);
                };
                LanguagePanel.Children.Add(button);
            }
        }

        private static string ShortName(string code) => code switch
        {
            "ru" => "RU",
            "kk" => "ҚАЗ",
            "en" => "EN",
            _ => code.ToUpperInvariant()
        };

        private static Brush Freeze(Brush brush)
        {
            brush.Freeze();
            return brush;
        }

        private async System.Threading.Tasks.Task SubmitAsync()
        {
            if (_busy) return;
            var phone = PhoneNumberFormatter.NormalizeKazakhstanNumber(PhoneBox.Text);
            if (phone is null)
            {
                ErrorText.Text = L.T("Введите 10 цифр номера после +7.");
                return;
            }

            _busy = true;
            SubmitButton.IsEnabled = false;
            ErrorText.Text = string.Empty;
            var allowRetry = true;

            try
            {
                var firstSetup = !_relogin;
                var result = _relogin
                    ? await _setup.ReloginAsync(phone, PasswordBox.Password, _cts.Token)
                    : await _setup.SetupAsync(ClubKeyBox.Password, phone, PasswordBox.Password, _cts.Token);

                if (result.Success)
                {
                    // Подключение ПК — только пока у него нет токена устройства: сервер выдаёт токен
                    // сотруднику, так агент узнаёт, что ПК подключил сотрудник, и выходит из его
                    // аккаунта. Когда ПК уже подключён, вход сотрудника или клиента — обычный вход.
                    var deviceId = await System.Threading.Tasks.Task.Run(() => DeviceInfo.Id);
                    var connect = firstSetup || !await System.Threading.Tasks.Task.Run(() => _setup.HasDeviceToken);
                    var claim = await _setup.ClaimComputerAsync(result.Value!, deviceId, _cts.Token, connect);
                    var isStaff = connect && claim.Success && !string.IsNullOrEmpty(claim.Value!.AgentToken);

                    // ПК не подтверждён (нет связи, служба не приняла токен): никто не остаётся
                    // в системе — иначе сотрудник мог бы остаться вошедшим вместо клиента.
                    if (!claim.Success && claim.Failure is not (ApiFailure.Forbidden or ApiFailure.ComputerNotFound) && !firstSetup)
                    {
                        await _setup.SignOutAsync(TimeSpan.FromSeconds(3));
                        ErrorText.Text = claim.Message;
                        PasswordBox.Clear();
                        SubmitButton.IsEnabled = true;
                        return;
                    }

                    // Сотрудник подключил (или обслужил) ПК и выходит: играют только под учётными записями клиентов.
                    if (isStaff)
                    {
                        await _setup.SignOutAsync(TimeSpan.FromSeconds(3));
                        ShowCustomerSignIn(claim.Value!);
                        return;
                    }

                    // Первое подключение ПК к клубу — только сотрудником.
                    if (firstSetup)
                    {
                        await _setup.SignOutAsync(TimeSpan.FromSeconds(3));
                        _setup.ResetClub();
                        ErrorText.Text = claim.Success || claim.Failure == ApiFailure.Forbidden
                            ? L.T("Подключать ПК к клубу может только сотрудник клуба: владелец, администратор, менеджер или оператор.")
                            : claim.Message;
                        PasswordBox.Clear();
                        SubmitButton.IsEnabled = true;
                        return;
                    }

                    Session = result.Value;
                    DialogResult = true;
                    return;
                }

                ErrorText.Text = result.Message;
                PasswordBox.Clear();

                // Повторять нельзя: нужно вмешательство администратора клуба.
                allowRetry = result.Failure is not (ApiFailure.KeyRevoked or ApiFailure.ClubMismatch
                    or ApiFailure.SubscriptionInactive or ApiFailure.InvalidEndpoint or ApiFailure.ClubAlreadyConfigured);
            }
            catch (OperationCanceledException)
            {
                return;
            }
            catch (Exception ex)
            {
                // Например, настройки нельзя записать: окно остаётся, агент не падает.
                SafeLog.Write("[Setup] sign-in failed: " + ex.Message);
                ErrorText.Text = L.T("Не удалось выполнить вход. Повторите попытку или обратитесь к администратору клуба.");
            }
            finally
            {
                _busy = false;
            }

            SubmitButton.IsEnabled = allowRetry;
        }

        /// <summary>ПК подключён сотрудником: окно ждёт вход клиента.</summary>
        private void ShowCustomerSignIn(ComputerInfo computer)
        {
            _relogin = true;
            _mode = Mode.Connected;
            _connectedNumber = computer.Number;
            _connectedZone = computer.ZoneName;
            ApplyTexts();
            KeyPanel.Visibility = Visibility.Collapsed;
            MinimizeButton.Visibility = Visibility.Collapsed;
            ClubKeyBox.Clear();
            PhoneBox.Clear();
            PasswordBox.Clear();
            ErrorText.Text = string.Empty;
            SubmitButton.IsEnabled = true;
            App.Instance.RefreshKiosk();
            PhoneBox.Focus();
            RegisterToggle.Visibility = Visibility.Visible;
            _ = RunQrLoginAsync();
            _ = RunGuestWatchAsync();
        }

        /// <summary>Регистрация на этом ПК вместо входа: только на подключённом ПК (у клиента).</summary>
        private void SetRegistering(bool registering)
        {
            if (_busy) return;
            _registering = registering;
            NamePanel.Visibility = registering ? Visibility.Visible : Visibility.Collapsed;
            ConfirmPanel.Visibility = registering ? Visibility.Visible : Visibility.Collapsed;
            ErrorText.Text = string.Empty;
            ApplyTexts();
            if (registering) NameBox.Focus(); else PhoneBox.Focus();
        }

        private async Task RegisterAsync()
        {
            if (_busy) return;
            var name = NameBox.Text.Trim();
            var phone = PhoneNumberFormatter.NormalizeKazakhstanNumber(PhoneBox.Text);
            if (name.Length < 2) { ErrorText.Text = L.T("Введите имя."); return; }
            if (phone is null) { ErrorText.Text = L.T("Введите 10 цифр номера после +7."); return; }
            if (PasswordBox.Password.Length < 6) { ErrorText.Text = L.T("Пароль — не короче 6 символов."); return; }
            if (PasswordBox.Password != ConfirmBox.Password) { ErrorText.Text = L.T("Пароли не совпадают."); return; }

            _busy = true;
            SubmitButton.IsEnabled = false;
            ErrorText.Text = string.Empty;
            try
            {
                var result = await _setup.RegisterAsync(name, phone, PasswordBox.Password, _cts.Token);
                if (result.Success)
                {
                    Session = result.Value;
                    DialogResult = true;
                    return;
                }

                ErrorText.Text = result.Message;
            }
            catch (OperationCanceledException)
            {
                return;
            }
            catch (Exception ex)
            {
                SafeLog.Write("[Register] failed: " + ex.Message);
                ErrorText.Text = L.T("Не удалось зарегистрироваться. Повторите попытку или обратитесь к администратору клуба.");
            }
            finally
            {
                _busy = false;
                SubmitButton.IsEnabled = true;
            }
        }

        /// <summary>
        /// Гостевая сессия: кассир подключил тариф на этом ПК без аккаунта клиента. Пока она идёт,
        /// окно входа свёрнуто и ПК открыт; в углу экрана — оставшееся время. Время вышло или
        /// сессию завершили в клубе — ПК снова блокируется окном входа.
        /// </summary>
        private async Task RunGuestWatchAsync()
        {
            if (_guestWatching) return;
            _guestWatching = true;
            try
            {
                while (!_cts.IsCancellationRequested)
                {
                    var check = await _setup.CheckGuestSessionAsync(_cts.Token);
                    if (check.Success) ApplyGuestSession(check.Value);
                    else if (check.Failure is ApiFailure.DeviceTokenInvalid) return; // ПК ещё не подключён
                    await Task.Delay(_guest is null ? TimeSpan.FromSeconds(5) : TimeSpan.FromSeconds(15), _cts.Token);
                }
            }
            catch (OperationCanceledException)
            {
            }
            catch (Exception ex)
            {
                SafeLog.Write("[Guest] watch stopped: " + ex.Message);
            }
            finally
            {
                _guestWatching = false;
            }
        }

        private DateTimeOffset ServerNow => DateTimeOffset.UtcNow + (_guest?.ClockOffset ?? TimeSpan.Zero);

        private void ApplyGuestSession(GuestSession? session)
        {
            if (session is not null && session.EndsAtUtc > DateTimeOffset.UtcNow + session.ClockOffset)
            {
                var starting = _guest is null;
                _guest = session;
                if (!starting) return;

                SafeLog.Write("[Guest] session started");
                App.Instance.Keyboard.Strict = false;
                App.Instance.Covers.Update(null, false);
                Topmost = false;
                WindowState = WindowState.Minimized;
                ShowGuestOverlay();
                _guestTick.Start();
                ShowGuestTime();
                return;
            }

            if (_guest is not null) EndGuestSession();
        }

        private void EndGuestSession()
        {
            SafeLog.Write("[Guest] session over: the PC is locked again");
            _guest = null;
            _guestTick.Stop();
            _guestOverlay?.Close();
            _guestOverlay = null;
            try { SHMinimizeAll(); } catch (Exception ex) { SafeLog.Write("[Guest] could not minimize windows: " + ex.Message); }
            WindowState = WindowState.Maximized;
            Topmost = App.Instance.KioskEnabled;
            App.Instance.Keyboard.Strict = true;
            App.Instance.Covers.Update(this, App.Instance.KioskEnabled);
            Activate();
            PhoneBox.Focus();
        }

        private void ShowGuestTime()
        {
            if (_guest is null || _guestOverlayText is null) return;
            var left = _guest.EndsAtUtc - ServerNow;
            if (left <= TimeSpan.Zero)
            {
                EndGuestSession();
                return;
            }

            _guestOverlayText.Text = L.T("Гостевая сессия · осталось :time", ("time", left.TotalHours >= 1 ? left.ToString(@"h\:mm\:ss") : left.ToString(@"mm\:ss")));
            _guestOverlayText.Foreground = left <= TimeSpan.FromMinutes(5) ? new SolidColorBrush(Color.FromRgb(0xFF, 0xB4, 0x54)) : Brushes.White;
        }

        /// <summary>Небольшая плашка в правом верхнем углу: не забирает фокус у игры.</summary>
        private void ShowGuestOverlay()
        {
            _guestOverlayText = new TextBlock { FontSize = 14, FontWeight = FontWeights.SemiBold, Foreground = Brushes.White, Margin = new Thickness(14, 8, 14, 8) };
            var area = SystemParameters.WorkArea;
            _guestOverlay = new Window
            {
                WindowStyle = WindowStyle.None,
                AllowsTransparency = true,
                Background = Brushes.Transparent,
                ResizeMode = ResizeMode.NoResize,
                ShowInTaskbar = false,
                ShowActivated = false,
                Focusable = false,
                Topmost = true,
                SizeToContent = SizeToContent.WidthAndHeight,
                Content = new Border { Background = new SolidColorBrush(Color.FromArgb(0xCC, 0x1B, 0x19, 0x25)), CornerRadius = new CornerRadius(10), Child = _guestOverlayText },
                Left = area.Right - 300,
                Top = area.Top + 12
            };
            _guestOverlay.Loaded += (_, _) => _guestOverlay.Left = area.Right - _guestOverlay.ActualWidth - 12;
            _guestOverlay.Show();
        }

        [System.Runtime.InteropServices.DllImport("shell32.dll")]
        private static extern void SHMinimizeAll();

        /// <summary>
        /// Вход с телефона: QR-код со ссылкой на сайт, пока окно открыто. Клиент входит на сайте
        /// и подтверждает вход — агент получает его токен и закрывает окно, как после пароля.
        /// Код живёт 5 минут, затем показывается новый. Клуб выключил QR-вход — блок скрыт.
        /// </summary>
        private async Task RunQrLoginAsync()
        {
            if (_qrRunning) return;
            _qrRunning = true;
            try
            {
                while (!_cts.IsCancellationRequested)
                {
                    var start = await _setup.StartQrLoginAsync(_cts.Token);
                    if (!start.Success)
                    {
                        QrPanel.Visibility = Visibility.Collapsed;
                        if (start.Failure is ApiFailure.Forbidden or ApiFailure.DeviceTokenInvalid) return;
                        await Task.Delay(TimeSpan.FromSeconds(30), _cts.Token);
                        continue;
                    }

                    var code = start.Value!;
                    QrImage.Source = QrBitmap(code.Url);
                    QrPanel.Visibility = Visibility.Visible;
                    var renewAt = DateTime.UtcNow + code.Lifetime - TimeSpan.FromSeconds(10);

                    while (DateTime.UtcNow < renewAt)
                    {
                        await Task.Delay(code.PollInterval, _cts.Token);
                        if (_busy) continue; // идёт вход по паролю

                        var poll = await _setup.PollQrLoginAsync(code.Id, _cts.Token);
                        if (poll.Success && poll.Value is { } session)
                        {
                            if (!IsVisible) return;
                            Session = session;
                            DialogResult = true;
                            return;
                        }

                        if (!poll.Success && poll.Failure is ApiFailure.Forbidden or ApiFailure.DeviceTokenInvalid)
                        {
                            QrPanel.Visibility = Visibility.Collapsed;
                            return;
                        }

                        if (!poll.Success && poll.Failure is ApiFailure.TokenExpired or ApiFailure.ClubMismatch or ApiFailure.InvalidResponse)
                            break; // новый QR-код
                    }
                }
            }
            catch (OperationCanceledException)
            {
            }
            catch (Exception ex)
            {
                SafeLog.Write("[QrLogin] stopped: " + ex.Message);
                QrPanel.Visibility = Visibility.Collapsed;
            }
            finally
            {
                _qrRunning = false;
            }
        }

        private static BitmapImage QrBitmap(Uri url)
        {
            using var generator = new QRCoder.QRCodeGenerator();
            using var data = generator.CreateQrCode(url.AbsoluteUri, QRCoder.QRCodeGenerator.ECCLevel.M);
            var png = new QRCoder.PngByteQRCode(data).GetGraphic(8);
            using var stream = new MemoryStream(png);
            var image = new BitmapImage();
            image.BeginInit();
            image.CacheOption = BitmapCacheOption.OnLoad;
            image.StreamSource = stream;
            image.EndInit();
            image.Freeze();
            return image;
        }
    }
}
