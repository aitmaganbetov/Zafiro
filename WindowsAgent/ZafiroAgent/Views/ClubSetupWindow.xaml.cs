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
using ZafiroAgent.Infrastructure;
using ZafiroAgent.Models;
using ZafiroAgent.Services;
using ZafiroAgent.Localization;

namespace ZafiroAgent.Views
{
    public partial class ClubSetupWindow : Window
    {
        private enum Mode { Setup, Relogin, Connected }

        // «Забыли пароль?»: None — обычный вход, Phone — номер для кода, Code — код из Telegram и новый пароль.
        private enum Recovery { None, Phone, Code }

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
        private Recovery _recovery;
        private string? _recoveryPhone;
        private bool _guestWatching;
        private GuestSession? _guest;
        private Window? _guestOverlay;
        private TextBlock? _guestOverlayText;
        private readonly System.Windows.Threading.DispatcherTimer _guestTick = new() { Interval = TimeSpan.FromSeconds(1) };
        private static readonly TimeSpan GuestWatchInterval = TimeSpan.FromSeconds(15);
        // Выключение/перезагрузка ждёт второго нажатия: null — ничего, true — перезагрузка, false — выключение.
        private bool? _powerArmed;
        // Картинка клуба на экране входа: скачанная — на всё время работы агента, неудачная — повтор через 5 минут.
        private static (string Url, ImageSource Image)? _signInImageCache;
        private static (string Url, DateTime RetryAtUtc)? _signInImageFailure;
        private ImageSource? _standardSignInImage;
        private string? _shownSignInImageUrl;
        private readonly System.Windows.Threading.DispatcherTimer _powerArmTimer = new() { Interval = TimeSpan.FromSeconds(5) };

        public ClubSession? Session { get; private set; }

        public ClubSetupWindow(ClubSetupService setup, string? notice = null)
        {
            InitializeComponent();
            _setup = setup;
            _standardSignInImage = SignInImage.Source;
            ShowSignInImage();
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
                if (_recovery != Recovery.None) await RecoverAsync();
                else if (_registering) await RegisterAsync();
                else await SubmitAsync();
            };
            RegisterToggle.Click += (_, _) => SetRegistering(!_registering);
            // Со входа — к номеру для кода; с номера — обратно ко входу; с кода — заново к номеру.
            ForgotToggle.Click += (_, _) => SetRecovery(_recovery == Recovery.Phone ? Recovery.None : Recovery.Phone);
            PhoneBox.TextChanged += (_, _) => FormatPhone();
            InputLanguageButton.Click += (_, _) => NextInputLanguage();
            AttachReveal(PasswordBox, PasswordReveal, PasswordEye);
            AttachReveal(ConfirmBox, ConfirmReveal, ConfirmEye);
            ShutdownButton.Click += (_, _) => PowerClicked(restart: false);
            RestartButton.Click += (_, _) => PowerClicked(restart: true);
            _powerArmTimer.Tick += (_, _) => SetPowerArmed(null);
            InputLanguageManager.Current.InputLanguageChanged += OnInputLanguageChanged;
            ShowInputLanguage();
            // QR-код — только на подключённом ПК (у него есть токен устройства).
            Loaded += (_, _) =>
            {
                if (!_relogin) return;
                RegisterToggle.Visibility = Visibility.Visible;
                ForgotToggle.Visibility = Visibility.Visible;
                _ = RunQrLoginAsync();
                _ = RunGuestWatchAsync();
            };
            _guestTick.Tick += (_, _) => ShowGuestTime();
            L.Changed += OnLanguageChanged;
            Closed += (_, _) =>
            {
                _cts.Cancel();
                _guestTick.Stop();
                _powerArmTimer.Stop();
                _guestOverlay?.Close();
                L.Changed -= OnLanguageChanged;
                InputLanguageManager.Current.InputLanguageChanged -= OnInputLanguageChanged;
            };
        }

        /// <summary>Номер ПК и зона: после подключения — от сервера (окно обновляет их с каждой проверкой).</summary>
        private void ShowComputer()
        {
            var computer = _connectedNumber ?? (string.IsNullOrWhiteSpace(_setup.ComputerNumber) ? _setup.ComputerId : _setup.ComputerNumber);
            var zone = _mode == Mode.Connected ? _connectedZone : _setup.Zone;
            ComputerText.Text = L.T("ПК: :computer", ("computer", string.IsNullOrWhiteSpace(computer) ? L.T("не задан") : computer));
            ZoneText.Text = L.T("Зона: :zone", ("zone", string.IsNullOrWhiteSpace(zone) ? L.T("не задана") : zone));
            VersionText.Text = L.T("Версия агента: :version", ("version", DeviceInfo.AgentVersion));
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
            if (PasswordReveal.IsVisible) PasswordReveal.Focus();
            else if (PasswordBox.IsVisible) PasswordBox.Focus();
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

        /// <summary>
        /// Глаз у поля пароля: пароль виден текстом в поле поверх, оба поля держат одно значение.
        /// Очищенный пароль (вход, смена режима) снова скрыт — следующий клиент не увидит свой ввод открытым.
        /// </summary>
        private void AttachReveal(PasswordBox box, TextBox reveal, Button eye)
        {
            eye.Click += (_, _) => SetRevealed(box, reveal, eye, reveal.Visibility != Visibility.Visible, focus: true);
            reveal.TextChanged += (_, _) =>
            {
                if (box.Password != reveal.Text) box.Password = reveal.Text;
            };
            box.PasswordChanged += (_, _) =>
            {
                if (reveal.Text != box.Password) reveal.Text = box.Password;
                if (box.Password.Length == 0 && !reveal.IsKeyboardFocusWithin) SetRevealed(box, reveal, eye, false, focus: false);
            };
            SetRevealed(box, reveal, eye, false, focus: false);
        }

        private static void SetRevealed(PasswordBox box, TextBox reveal, Button eye, bool revealed, bool focus)
        {
            reveal.Visibility = revealed ? Visibility.Visible : Visibility.Collapsed;
            box.Visibility = revealed ? Visibility.Hidden : Visibility.Visible;
            ShowEyeText(eye, revealed);
            if (!focus) return;
            if (revealed)
            {
                reveal.Focus();
                reveal.CaretIndex = reveal.Text.Length;
            }
            else box.Focus();
        }

        private static void ShowEyeText(Button eye, bool revealed)
        {
            eye.Content = revealed ? "\uED1A" : "\uE7B3";
            eye.ToolTip = revealed ? L.T("Скрыть пароль") : L.T("Показать пароль");
            System.Windows.Automation.AutomationProperties.SetName(eye, (string)eye.ToolTip);
        }

        /// <summary>Все тексты окна на текущем языке: при смене языка окно не теряет введённые данные.</summary>
        private void ApplyTexts()
        {
            Title = L.T("Подключение к клубу Zafiro");
            ShowEyeText(PasswordEye, PasswordReveal.Visibility == Visibility.Visible);
            ShowEyeText(ConfirmEye, ConfirmReveal.Visibility == Visibility.Visible);
            ShowComputer();
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
            MinimizeButton.Content = L.T("Свернуть, чтобы скопировать ключ");
            QrTitle.Text = L.T("Вход с телефона");
            QrHint.Text = L.T("Наведите камеру телефона на QR-код, войдите на сайте и подтвердите вход.");
            CodeLabel.Text = L.T("Код из Telegram");
            ForgotToggle.Content = _recovery switch
            {
                Recovery.Phone => L.T("Вернуться ко входу"),
                Recovery.Code => L.T("Запросить новый код"),
                _ => L.T("Забыли пароль?")
            };
            if (_recovery != Recovery.None)
            {
                HeaderText.Text = L.T("Восстановление пароля");
                ClubText.Text = _recovery == Recovery.Phone
                    ? L.T("Пришлём код в Telegram на номер вашего аккаунта.")
                    : L.T("Введите код из Telegram и придумайте новый пароль.");
                PasswordLabel.Text = L.T("Новый пароль");
                SubmitButton.Content = _recovery == Recovery.Phone ? L.T("Получить код в Telegram") : L.T("Сменить пароль");
            }
            ShowPowerButtons();
            RenderLanguages();
        }

        /// <summary>Первое нажатие — подсказка «нажмите ещё раз» на 5 секунд, второе — выключение или перезагрузка.</summary>
        private void PowerClicked(bool restart)
        {
            if (_powerArmed != restart)
            {
                SetPowerArmed(restart);
                return;
            }

            SetPowerArmed(null);
            SafeLog.Write("[Power] " + (restart ? "restart" : "shutdown") + " from the sign-in screen");
            if (!PowerControl.Run(restart))
                ErrorText.Text = L.T("Windows не дал выключить ПК. Обратитесь к администратору клуба.");
        }

        private void SetPowerArmed(bool? restart)
        {
            _powerArmed = restart;
            _powerArmTimer.Stop();
            if (restart is not null) _powerArmTimer.Start();
            ShowPowerButtons();
        }

        private void ShowPowerButtons()
        {
            foreach (var (button, restart, name) in new[] { (ShutdownButton, false, L.T("Выключить ПК")), (RestartButton, true, L.T("Перезагрузить ПК")) })
            {
                button.Tag = _powerArmed == restart ? "armed" : null;
                button.ToolTip = name;
                System.Windows.Automation.AutomationProperties.SetName(button, name);
            }

            PowerHint.Text = _powerArmed switch
            {
                true => L.T("Нажмите ещё раз, чтобы перезагрузить ПК."),
                false => L.T("Нажмите ещё раз, чтобы выключить ПК."),
                null => string.Empty
            };
            PowerHint.Visibility = _powerArmed is null ? Visibility.Collapsed : Visibility.Visible;
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
                    var (deviceId, mac) = await System.Threading.Tasks.Task.Run(() => (DeviceInfo.Id, DeviceInfo.MacAddress));
                    var connect = firstSetup || !await System.Threading.Tasks.Task.Run(() => _setup.HasDeviceToken);
                    var claim = await _setup.ClaimComputerAsync(result.Value!, deviceId, _cts.Token, connect, mac);
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
            NoticeText.Visibility = Visibility.Collapsed;
            ApplyTexts();
            if (registering) NameBox.Focus(); else PhoneBox.Focus();
        }

        private void SetRecovery(Recovery step)
        {
            if (_busy) return;
            _recovery = step;
            _registering = false;
            NamePanel.Visibility = Visibility.Collapsed;
            CodePanel.Visibility = step == Recovery.Code ? Visibility.Visible : Visibility.Collapsed;
            PasswordPanel.Visibility = step == Recovery.Phone ? Visibility.Collapsed : Visibility.Visible;
            ConfirmPanel.Visibility = step == Recovery.Code ? Visibility.Visible : Visibility.Collapsed;
            // На шаге кода номер уже выбран: код пришёл именно на него.
            PhoneBox.IsReadOnly = step == Recovery.Code;
            RegisterToggle.Visibility = step == Recovery.None && _relogin ? Visibility.Visible : Visibility.Collapsed;
            CodeBox.Clear();
            PasswordBox.Clear();
            ConfirmBox.Clear();
            ErrorText.Text = string.Empty;
            NoticeText.Visibility = Visibility.Collapsed;
            ApplyTexts();
            if (step == Recovery.Code) CodeBox.Focus();
            else if (step == Recovery.Phone) PhoneBox.Focus();
            else PasswordBox.Focus();
        }

        /// <summary>Картинка клуба вместо стандартной (настройки клуба на сайте); удалили — снова стандартная.</summary>
        private async void ShowSignInImage()
        {
            var url = _setup.SignInImageUrl;
            if (url == _shownSignInImageUrl) return;
            if (url is null)
            {
                _shownSignInImageUrl = null;
                SignInImage.Source = _standardSignInImage;
                return;
            }
            if (_signInImageCache is { } cached && cached.Url == url)
            {
                _shownSignInImageUrl = url;
                SignInImage.Source = cached.Image;
                return;
            }
            if (_signInImageFailure is { } failed && failed.Url == url && DateTime.UtcNow < failed.RetryAtUtc) return;

            _shownSignInImageUrl = url;
            try
            {
                var image = await Task.Run(() => RemoteImage.DownloadAsync(url, 4 * 1024 * 1024, 2560));
                if (image is null)
                {
                    _shownSignInImageUrl = null;
                    _signInImageFailure = (url, DateTime.UtcNow.AddMinutes(5));
                    return;
                }
                _signInImageCache = (url, image);
                // Пока картинка скачивалась, клуб мог сменить её ещё раз.
                if (_shownSignInImageUrl == url) SignInImage.Source = image;
            }
            catch (Exception ex)
            {
                _shownSignInImageUrl = null;
                _signInImageFailure = (url, DateTime.UtcNow.AddMinutes(5));
                SafeLog.Write("[SignIn] could not load the club picture: " + ex.GetType().Name);
            }
        }

        private void ShowNotice(string text)
        {
            NoticeText.Text = text;
            NoticeText.Visibility = Visibility.Visible;
        }

        /// <summary>Шаг восстановления: запросить код в Telegram или сменить пароль по коду.</summary>
        private async Task RecoverAsync()
        {
            if (_busy) return;
            var step = _recovery;
            var phone = step == Recovery.Code ? _recoveryPhone : PhoneNumberFormatter.NormalizeKazakhstanNumber(PhoneBox.Text);
            if (phone is null) { ErrorText.Text = L.T("Введите 10 цифр номера после +7."); return; }
            var code = CodeBox.Text.Trim();
            if (step == Recovery.Code)
            {
                if (code.Length != 6 || !code.All(char.IsAsciiDigit)) { ErrorText.Text = L.T("Код — 6 цифр из сообщения в Telegram."); return; }
                if (PasswordBox.Password.Length < 6) { ErrorText.Text = L.T("Пароль — не короче 6 символов."); return; }
                if (PasswordBox.Password != ConfirmBox.Password) { ErrorText.Text = L.T("Пароли не совпадают."); return; }
            }

            _busy = true;
            SubmitButton.IsEnabled = false;
            ErrorText.Text = string.Empty;
            NoticeText.Visibility = Visibility.Collapsed;
            string? message = null;
            var done = false;
            try
            {
                var result = step == Recovery.Phone
                    ? await _setup.RequestPasswordCodeAsync(phone, _cts.Token)
                    : await _setup.ResetPasswordAsync(phone, code, PasswordBox.Password, _cts.Token);
                if (!result.Success)
                {
                    ErrorText.Text = result.Message;
                    return;
                }

                if (result.Value.ValueKind == System.Text.Json.JsonValueKind.Object &&
                    result.Value.TryGetProperty("message", out var text) && text.ValueKind == System.Text.Json.JsonValueKind.String)
                    message = text.GetString();
                done = true;
            }
            catch (OperationCanceledException)
            {
                return;
            }
            catch (Exception ex)
            {
                SafeLog.Write("[Recovery] failed: " + ex.GetType().Name);
                ErrorText.Text = L.T("Не удалось восстановить пароль. Повторите попытку или обратитесь к администратору клуба.");
            }
            finally
            {
                _busy = false;
                SubmitButton.IsEnabled = true;
            }

            if (!done) return;
            if (step == Recovery.Phone)
            {
                _recoveryPhone = phone;
                SetRecovery(Recovery.Code);
                ShowNotice(message ?? L.T("Код отправлен в Telegram."));
            }
            else
            {
                // Пароль сменён: снова вход, номер уже введён — остаётся новый пароль.
                SetRecovery(Recovery.None);
                ShowNotice(message ?? L.T("Пароль изменён. Войдите с новым паролем."));
            }
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
                    if (check.Success)
                    {
                        ShowComputer();
                        ShowSignInImage();
                        ApplyGuestSession(check.Value);
                        // Сотрудник перезагрузил или выключил ПК из панели или приложения.
                        var staffCommand = _setup.TakePowerCommand();
                        if (staffCommand is "service")
                        {
                            // Техрежим из панели — как Ctrl+Alt+P с PIN-кодом сотрудника.
                            App.Instance.ExitForStaff("technical mode from the panel (sign-in screen)");
                        }
                        else if (staffCommand is { } command)
                        {
                            SafeLog.Write("[Power] " + command + " by staff from the sign-in screen");
                            if (!PowerControl.Run(command == "restart"))
                                ErrorText.Text = L.T("Windows не дал выключить ПК. Обратитесь к администратору клуба.");
                        }
                        // Клуб включил QR, пока экран входа открыт: код появляется без перезапуска.
                        if (_relogin && _setup.QrLoginEnabled == true && !_qrRunning) _ = RunQrLoginAsync();
                    }
                    // Раз в 15 с: этот запрос — почти вся нагрузка свободных ПК на сервер, а гостевое время,
                    // которое подключил кассир, открывает ПК не позже чем через 15 с. Пока токена ПК нет
                    // (служба получает его по ключу клуба после загрузки), проверка идёт с тем же шагом.
                    await Task.Delay(GuestWatchInterval, _cts.Token);
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
                // Включён ли QR в клубе, приходит с первой проверкой гостевой сессии (до ~10 с):
                // выключенный QR не запрашивается вовсе. Неизвестно (старый сервер) — пробуем, как раньше.
                for (var wait = 0; wait < 20 && _setup.QrLoginEnabled is null; wait++)
                    await Task.Delay(500, _cts.Token);
                if (_setup.QrLoginEnabled == false)
                {
                    QrPanel.Visibility = Visibility.Collapsed;
                    return;
                }

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
