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

        public ClubSession? Session { get; private set; }

        public ClubSetupWindow(ClubSetupService setup, string? notice = null)
        {
            InitializeComponent();
            _setup = setup;
            _relogin = setup.IsClubConfigured;
            _mode = _relogin ? Mode.Relogin : Mode.Setup;
            if (_relogin) KeyPanel.Visibility = Visibility.Collapsed;

            ApplyTexts();
            if (!string.IsNullOrEmpty(notice)) ErrorText.Text = notice;

            SubmitButton.Click += async (_, _) => await SubmitAsync();
            PhoneBox.TextChanged += (_, _) => FormatPhone();
            InputLanguageButton.Click += (_, _) => NextInputLanguage();
            InputLanguageManager.Current.InputLanguageChanged += OnInputLanguageChanged;
            ShowInputLanguage();
            // QR-код — только на подключённом ПК (у него есть токен устройства).
            Loaded += (_, _) => { if (_relogin) _ = RunQrLoginAsync(); };
            L.Changed += OnLanguageChanged;
            Closed += (_, _) =>
            {
                _cts.Cancel();
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
            SubmitButton.Content = L.T("Войти");
            StaffHint.Text = L.T("Сотрудник клуба: Ctrl+Alt+P — выход из агента по PIN-коду.");
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
            ClubKeyBox.Clear();
            PhoneBox.Clear();
            PasswordBox.Clear();
            ErrorText.Text = string.Empty;
            SubmitButton.IsEnabled = true;
            App.Instance.RefreshKiosk();
            PhoneBox.Focus();
            _ = RunQrLoginAsync();
        }

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
