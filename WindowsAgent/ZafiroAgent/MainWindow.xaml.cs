using Microsoft.Web.WebView2.Core;
using System;
using System.IO;
using System.Net.Http;
using System.Runtime.InteropServices;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Threading;
using ZafiroAgent.Models;
using ZafiroAgent.Security;
using ZafiroAgent.Services;
using ZafiroAgent.Infrastructure;
using ZafiroAgent.Localization;

namespace ZafiroAgent
{
    // Перенаправляет вызовы Debug.WriteLine в SafeLog: ключ, пароль и токен маскируются до вывода.
    internal static class Debug
    {
        public static void WriteLine(string message) => SafeLog.Write(message);
    }

    public partial class MainWindow : Window
    {
        // Интерфейс агента открывается только с этого виртуального адреса (папка Frontend рядом с exe).
        private const string AppHost = "appassets.local";
        private const string AppOrigin = "https://" + AppHost + "/";

        // Пока покупка только что прошла, сервер может ещё не прислать сессию в heartbeat.
        private static readonly TimeSpan SessionSyncGrace = TimeSpan.FromSeconds(20);

        private readonly IApiService _apiService = new HttpApiService();
        private readonly ZafiroApiClient _zafiroApi;
        private readonly ConfigurationService _cfgService;
        private readonly ClubSetupService _clubSetup;
        private readonly DispatcherTimer _sessionTimer = new() { Interval = TimeSpan.FromSeconds(5) };
        private HeartbeatService? _heartbeatService;
        private TrayIcon? _trayIcon;
        private bool _authPromptOpen;
        private bool _closeFlushed;
        private bool _reloginTried;
        private bool _showingDiagnosticPage;

        // Оплаченная сессия на этом ПК (по данным сервера). Без неё рабочий стол недоступен.
        private string? _activeSessionId;
        private DateTimeOffset? _activeSessionEndsAt;
        // Предупреждения о конце сессии: за сколько минут, и для какого времени конца они уже были.
        private static readonly int[] SessionWarningMinutes = { 10, 5, 3, 1 };
        private DateTimeOffset? _warnedForEnd;
        private int _warnedMinutes = int.MaxValue;
        private DateTime _sessionAdoptedAtUtc;

        // Часы сервера минус часы ПК: если часы ПК спешат или отстают, сессия всё равно
        // заканчивается вовремя, а не раньше (и игра не сворачивается каждые 5 секунд).
        private TimeSpan _clockOffset;
        private bool _purchaseInFlight;
        private readonly DispatcherTimer _lockGuard = new() { Interval = TimeSpan.FromSeconds(1) };
        private static readonly TimeSpan BalanceRefreshInterval = TimeSpan.FromSeconds(20);
        private DateTime _balanceRefreshedAtUtc;
        private bool _balanceRefreshing;
        private DateTime _webViewRecreatedAtUtc;

        // Действие с ПК после конца сессии (настройки клуба из heartbeat): перезагрузка или выключение,
        // если за отведённое время клиент не оплатил новую сессию.
        private string _afterSessionAction = "none";
        private TimeSpan _afterSessionDelay = TimeSpan.FromSeconds(30);
        private readonly DispatcherTimer _afterSessionTimer = new();

        // Последние «Гости» клуба из heartbeat: страница получает их сразу при загрузке (после
        // входа клиента), а не через ~30 с, когда придёт следующий heartbeat.
        private Dictionary<string, bool>? _guestFeatures;
        private int? _idleLogoutMinutes;

        private DateTimeOffset ServerNow => DateTimeOffset.UtcNow + _clockOffset;

        [DllImport("shell32.dll")]
        private static extern void SHMinimizeAll();

        private const int SwMinimize = 6;

        [DllImport("user32.dll")]
        private static extern IntPtr GetForegroundWindow();

        [DllImport("user32.dll")]
        private static extern uint GetWindowThreadProcessId(IntPtr window, out uint processId);

        [DllImport("user32.dll")]
        private static extern bool ShowWindow(IntPtr window, int command);

        [DllImport("user32.dll", CharSet = CharSet.Unicode)]
        private static extern int GetClassName(IntPtr window, System.Text.StringBuilder className, int maxCount);

        public MainWindow(ConfigurationService config, ClubSetupService clubSetup, ZafiroApiClient api)
        {
            _cfgService = config;
            _clubSetup = clubSetup;
            _zafiroApi = api;
            InitializeComponent();
            UiError.Text = L.T("Интерфейс агента не загрузился. Обратитесь к администратору клуба.");
            L.Changed += OnLanguageChanged;
            InitializeTrayIcon();
            Loaded += MainWindow_Loaded;
#if DEBUG
            KeyDown += MainWindow_KeyDown;
#endif
            _sessionTimer.Tick += (_, _) => CheckSessionTime();
            _lockGuard.Tick += (_, _) => GuardLockedScreen();
            _afterSessionTimer.Tick += async (_, _) => await RunAfterSessionActionAsync();
        }

        private void InitializeTrayIcon()
        {
            // Выход — только по PIN-коду сотрудника (в режиме киоска).
            _trayIcon = new TrayIcon("Zafiro Agent", (L.T("Открыть Zafiro"), RestoreFromTray), (L.T("Выход"), RequestExit));
            _trayIcon.Open += RestoreFromTray;
        }

        private void MinimizeToTray()
        {
            try
            {
                SHMinimizeAll();
            }
            catch (Exception ex)
            {
                Debug.WriteLine("[Desktop] could not minimize windows: " + ex.Message);
            }

            ShowInTaskbar = false;
            Hide();
        }

        private void RestoreFromTray()
        {
            ShowInTaskbar = true;
            WindowState = WindowState.Maximized;
            Show();
            ApplyKioskMode();
            Activate();
        }

        private void RequestExit()
        {
            if (App.Instance.KioskEnabled) App.Instance.RequestStaffExit();
            else Close();
        }

        /// <summary>
        /// Без оплаченной сессии агент поверх всех окон и клавиши выхода заблокированы;
        /// во время сессии клиент пользуется ПК, но закрыть агент без PIN по-прежнему нельзя.
        /// </summary>
        private void ApplyKioskMode()
        {
            App.Instance.RefreshKiosk();
            var paid = _activeSessionId is not null;
            App.Instance.Keyboard.Strict = !paid;
            Topmost = App.Instance.KioskEnabled && !paid;
            App.Instance.Covers.Update(this, Topmost && IsVisible);
            if (Topmost) _lockGuard.Start(); else _lockGuard.Stop();
        }

        /// <summary>
        /// Без оплаченной сессии на переднем плане может быть только агент: окно другой программы
        /// (например, игра на весь экран, оставшаяся после конца сессии) сворачивается.
        /// </summary>
        private void GuardLockedScreen()
        {
            if (!Topmost || !IsVisible || _activeSessionId is not null) return;
            var foreground = GetForegroundWindow();
            if (foreground == IntPtr.Zero) return;
            GetWindowThreadProcessId(foreground, out var processId);
            if (processId == (uint)Environment.ProcessId) return; // свои окна: вход, PIN-код, чёрные экраны

            // Панель задач и рабочий стол Windows не сворачиваются: агент просто встаёт поверх них.
            var className = new System.Text.StringBuilder(64);
            GetClassName(foreground, className, className.Capacity);
            if (className.ToString() is not ("Shell_TrayWnd" or "Shell_SecondaryTrayWnd" or "Progman" or "WorkerW"))
                ShowWindow(foreground, SwMinimize);
            Activate();
        }

        /// <summary>
        /// Баланс и бонусы в агенте: пополнение на кассе видно без повторного входа. Сам — с heartbeat
        /// (не чаще раза в 20 с), по кнопке «Обновить» (manual) — сразу.
        /// </summary>
        private async Task RefreshBalanceAsync(bool manual = false)
        {
            if (_balanceRefreshing || (!manual && DateTime.UtcNow - _balanceRefreshedAtUtc < BalanceRefreshInterval)) return;
            _balanceRefreshing = true;
            try
            {
                var session = await _apiService.GetSessionInfoAsync(_activeSessionId ?? string.Empty, CancellationToken.None);
                _balanceRefreshedAtUtc = DateTime.UtcNow;
                SendMessageToWeb(JsonSerializer.Serialize(new { type = "balance", balance = session.Balance, bonus = session.BonusBalance, manual }));
            }
            catch (Exception ex)
            {
                Debug.WriteLine("[Balance] refresh failed: " + ex.Message);
                if (manual) SendMessageToWeb("{\"type\":\"balanceError\"}");
            }
            finally
            {
                _balanceRefreshing = false;
            }
        }

        // Финальный статус отправляется асинхронно: закрытие не блокирует UI-поток.
        protected override void OnClosing(System.ComponentModel.CancelEventArgs e)
        {
            // Режим киоска: закрыть агент (Alt+F4, «Выход») можно только после PIN-кода сотрудника.
            if (App.Instance.KioskEnabled && !App.Instance.ExitAuthorized)
            {
                e.Cancel = true;
                Dispatcher.BeginInvoke(App.Instance.RequestStaffExit);
                return;
            }

            if (!_closeFlushed && _heartbeatService is not null)
            {
                _closeFlushed = true;
                e.Cancel = true;
                _ = FlushAndCloseAsync();
                return;
            }

            base.OnClosing(e);
        }

        private async Task FlushAndCloseAsync()
        {
            try
            {
                if (_heartbeatService is { } heartbeat)
                    await heartbeat.StopAsync(ComputerState.ShuttingDown, TimeSpan.FromSeconds(2));
                // Пользователь выходит: токен отзывается на сервере, а не только стирается на ПК.
                await _clubSetup.SignOutAsync(TimeSpan.FromSeconds(2));
            }
            catch (Exception ex)
            {
                SafeLog.Write("[Heartbeat] final status failed: " + ex.Message);
            }

            Close();
        }

        // При закрытии выходит только пользователь: клуб, ключ установки и токен ПК сохраняются,
        // а оплаченная сессия остаётся на сервере до конца своего времени.
        protected override void OnClosed(EventArgs e)
        {
            L.Changed -= OnLanguageChanged;
            _sessionTimer.Stop();
            _lockGuard.Stop();
            _afterSessionTimer.Stop();

            try
            {
                _clubSetup.Logout();
            }
            catch (Exception ex)
            {
                SafeLog.Write("[Shutdown] logout failed: " + ex.Message);
            }

            StopHeartbeat();

            _trayIcon?.Dispose();

            base.OnClosed(e);
        }

        private async void MainWindow_Loaded(object? sender, RoutedEventArgs e)
        {
            // Вход в клуб уже проверен в App до открытия этого окна.
            StartHeartbeat();
            _sessionTimer.Start();
            ApplyKioskMode();

            try
            {
                await InitializeWebViewAsync();
            }
            catch (Exception ex)
            {
                // Без интерфейса ПК остаётся заблокированным; сотрудник выходит по PIN-коду.
                SafeLog.Write("[WebView] could not start: " + ex.Message);
                WebView.Visibility = Visibility.Collapsed;
                UiError.Visibility = Visibility.Visible;
            }
        }

        // Повторный вход поверх агента: отдельной кнопки на панели задач у окна нет.
        private bool ShowSignIn(string? notice = null)
        {
            var window = new Views.ClubSetupWindow(_clubSetup, notice) { Owner = this, ShowInTaskbar = false, Topmost = App.Instance.KioskEnabled };
            if (window.ShowDialog() != true || window.Session is null) return false;

            // Новый клиент: интерфейс загружается заново, без данных прежнего.
            WebView.CoreWebView2?.Navigate(AppOrigin + "index.html");
            return true;
        }

        private void StartHeartbeat()
        {
            StopHeartbeat();
            try
            {
                var service = new HeartbeatService(_cfgService, _clubSetup, _zafiroApi);
                service.OnHeartbeat += hb =>
                {
                    _reloginTried = false;

                    // Статус online/offline определяет сервер; агент его не вычисляет.
                    var payload = new
                    {
                        type = "heartbeat",
                        status = hb.Status,
                        connectionStatus = hb.ConnectionStatus,
                        lastSeenAt = hb.LastSeenAt?.ToString("o"),
                        recommendedInterval = hb.RecommendedIntervalSeconds,
                        serverTime = hb.ServerTimeUtc?.ToString("O"),
                        number = hb.Number,
                        zone = hb.Zone
                    };
                    SendMessageToWeb(JsonSerializer.Serialize(payload));
                    Dispatcher.BeginInvoke(() =>
                    {
                        if (hb.ServerTimeUtc is { } serverTime) _clockOffset = serverTime - DateTimeOffset.UtcNow;
                        SyncSessionWithServer(hb);
                        if (hb.Customer is { } customer)
                        {
                            // Баланс и счётчики пришли с heartbeat: отдельно сервер не спрашиваем.
                            _balanceRefreshedAtUtc = DateTime.UtcNow;
                            SendMessageToWeb(JsonSerializer.Serialize(new { type = "balance", balance = customer.Balance, bonus = customer.BonusBalance, manual = false }));
                            SendMessageToWeb(JsonSerializer.Serialize(new { type = "customerCounters", friendRequests = customer.FriendRequests, chatLastStaffId = customer.ChatLastStaffId }));
                        }
                        else
                        {
                            _ = RefreshBalanceAsync(); // сервер без блока customer: как раньше
                        }
                        if (hb.ClubReported) App.Instance.Covers.SetBranding(hb.ClubName, hb.ClubLogoUrl);
                        ApplyClubLanguage(hb);
                        ApplyAfterSessionSettings(hb);
                        // Друзья, переводы и пересадка: страница показывает только включённое клубом.
                        if (hb.GuestFeatures is { } features)
                        {
                            _guestFeatures = features;
                            _idleLogoutMinutes = hb.IdleLogoutMinutes;
                            SendGuestFeatures();
                        }
                    });
                };
                service.OnError += ex =>
                {
                    var failure = (ex as ZafiroApiException)?.Failure.ToString();
                    SendMessageToWeb(JsonSerializer.Serialize(new { type = "heartbeatError", failure, message = ex.Message }));

                    if (ex is ZafiroApiException { Failure: ApiFailure.TokenExpired })
                        _ = Dispatcher.InvokeAsync(HandleTokenExpiredAsync);
                };
                service.Start();
                _heartbeatService = service;
            }
            catch (Exception ex)
            {
                SafeLog.Write("[Heartbeat] init failed: " + ex.Message);
            }
        }

        /// <summary>
        /// Сервер — единственный источник правды о сессии: после перезапуска агент
        /// восстанавливает оплаченную сессию, а закончившуюся сразу завершает.
        /// </summary>
        private void SyncSessionWithServer(HeartbeatResponse hb)
        {
            if (hb.SessionExpired && _activeSessionId is not null)
            {
                EndSessionLocally("expired");
                return;
            }

            if (!hb.SessionReported) return; // сервер старой версии

            if (hb.SessionId is { } serverSessionId)
            {
                if (serverSessionId != _activeSessionId || hb.SessionEndsAtUtc != _activeSessionEndsAt)
                    AdoptSession(serverSessionId, hb.SessionEndsAtUtc);
            }
            else if (_activeSessionId is not null && DateTime.UtcNow - _sessionAdoptedAtUtc > SessionSyncGrace)
            {
                EndSessionLocally("server");
            }
        }

        private void AdoptSession(string sessionId, DateTimeOffset? endsAtUtc)
        {
            if (_activeSessionId != sessionId) _sessionAdoptedAtUtc = DateTime.UtcNow;
            _activeSessionId = sessionId;
            _activeSessionEndsAt = endsAtUtc;
            CancelAfterSessionAction(); // клиент оплатил новую сессию
            _heartbeatService?.SetState(ComputerState.InSession, sessionId);
            _heartbeatService?.SetSessionEnd(endsAtUtc);
            ApplyKioskMode();
            SendMessageToWeb(JsonSerializer.Serialize(new
            {
                type = "sessionSync",
                sessionId,
                clockOffsetMs = (long)_clockOffset.TotalMilliseconds,
                sessionEndsAt = endsAtUtc?.ToUniversalTime().ToString("O")
            }));
        }

        /// <summary>
        /// Сессия закончилась: агент снова поверх рабочего стола и ждёт оплаты. Клиент остаётся
        /// в своём аккаунте (может сразу купить тариф), но играть не может, пока не оплатит.
        /// </summary>
        private void EndSessionLocally(string reason)
        {
            // Время вышло (по часам ПК или сервер закрыл сессию в срок, а не сотрудник досрочно).
            var timeIsUp = _activeSessionId is not null && (reason == "expired" ||
                (reason == "server" && _activeSessionEndsAt is { } plannedEnd && plannedEnd <= ServerNow + TimeSpan.FromSeconds(30)));
            _activeSessionId = null;
            _activeSessionEndsAt = null;
            _heartbeatService?.SetState(ComputerState.Idle);
            SendMessageToWeb(JsonSerializer.Serialize(new { type = "sessionEnded", reason }));
            if (reason != "ended")
            {
                // Время вышло: сворачиваем игры и приложения, агент — поверх всех окон.
                try { SHMinimizeAll(); } catch (Exception ex) { Debug.WriteLine("[Desktop] could not minimize windows: " + ex.Message); }
            }
            RestoreFromTray(); // также включает блокировку клавиш и окно поверх всех
            ScheduleAfterSessionAction();
            // После агента: уведомление должно быть поверх его окна.
            if (timeIsUp) Views.SessionTimeNotice.Show(L.T("Истекло время сессии"), final: true);
        }

        private static bool IsPowerAction(string action) => action is "restart" or "shutdown";

        /// <summary>Настройки клуба из heartbeat; если клуб выключил действие, ожидающее отменяется.</summary>
        private void ApplyAfterSessionSettings(HeartbeatResponse hb)
        {
            if (hb.AfterSessionAction is not { } action) return; // сервер старой версии
            _afterSessionAction = IsPowerAction(action) ? action : "none";
            if (hb.AfterSessionDelaySeconds is { } seconds) _afterSessionDelay = TimeSpan.FromSeconds(seconds);
            if (_afterSessionAction == "none") CancelAfterSessionAction();
        }

        /// <summary>Сессия закончилась: через заданное время ПК перезагружается или выключается, а клиент видит отсчёт.</summary>
        private void ScheduleAfterSessionAction()
        {
            _afterSessionTimer.Stop();
            if (!IsPowerAction(_afterSessionAction)) return;
            _afterSessionTimer.Interval = _afterSessionDelay > TimeSpan.Zero ? _afterSessionDelay : TimeSpan.FromMilliseconds(1);
            _afterSessionTimer.Start();
            SendMessageToWeb(JsonSerializer.Serialize(new { type = "afterSession", action = _afterSessionAction, seconds = (int)_afterSessionDelay.TotalSeconds }));
        }

        private void CancelAfterSessionAction()
        {
            if (!_afterSessionTimer.IsEnabled) return;
            _afterSessionTimer.Stop();
            SendMessageToWeb("{\"type\":\"afterSessionCancelled\"}");
        }

        private async Task RunAfterSessionActionAsync()
        {
            _afterSessionTimer.Stop();
            var action = _afterSessionAction;
            if (_activeSessionId is not null || !IsPowerAction(action)) return;

            SafeLog.Write("[AfterSession] " + action + " after the session ended");
            try
            {
                // Последний статус уходит на сервер, клиент выходит: после включения ПК ждёт следующего.
                if (_heartbeatService is { } heartbeat)
                {
                    await heartbeat.StopAsync(ComputerState.ShuttingDown, TimeSpan.FromSeconds(2));
                    heartbeat.Dispose();
                    _heartbeatService = null;
                }
                await _clubSetup.SignOutAsync(TimeSpan.FromSeconds(2));
            }
            catch (Exception ex)
            {
                SafeLog.Write("[AfterSession] final status failed: " + ex.Message);
            }

            if (PowerControl.Run(action == "restart")) return;

            // Windows не дал выключить ПК: агент остаётся заблокированным и ждёт следующего входа.
            await HandleLogoutAsync();
        }

        private void CheckSessionTime()
        {
            App.Instance.RefreshKiosk(); // киоск включается, как только ПК получил токен устройства
            if (_activeSessionId is not null && _activeSessionEndsAt is { } endsAt && endsAt <= ServerNow)
                EndSessionLocally("expired");
            else
                WarnSessionTime();
        }

        /// <summary>
        /// За 10, 5, 3 и 1 минуту до конца — уведомление со звуком, каждое один раз. Время продлили —
        /// отсчёт заново, но без уведомления сразу после покупки.
        /// </summary>
        private void WarnSessionTime()
        {
            if (_activeSessionId is null || _activeSessionEndsAt is not { } endsAt) return;
            var left = endsAt - ServerNow;
            var reached = int.MaxValue;
            foreach (var minutes in SessionWarningMinutes)
                if (left <= TimeSpan.FromMinutes(minutes)) reached = Math.Min(reached, minutes);

            if (_warnedForEnd != endsAt)
            {
                _warnedForEnd = endsAt;
                _warnedMinutes = reached;
                return;
            }
            if (reached >= _warnedMinutes) return;

            _warnedMinutes = reached;
            var count = Math.Max(1, (int)Math.Ceiling(left.TotalMinutes));
            Views.SessionTimeNotice.Show(L.T("До конца сессии осталось :count мин.", ("count", count)), final: false);
        }

        // 401: вход обновляется один раз; повторный 401 после нового входа цикл не запускает.
        private async Task HandleTokenExpiredAsync()
        {
            if (_authPromptOpen || _reloginTried) return;
            _authPromptOpen = true;
            _reloginTried = true;

            try
            {
                StopHeartbeat();
                if (ShowSignIn())
                    StartHeartbeat();
                else
                    Close();
            }
            finally
            {
                _authPromptOpen = false;
            }

            await Task.CompletedTask;
        }

        private long? _translationsVersion;
        private bool _textsLoading;

        private void OnLanguageChanged() => Dispatcher.BeginInvoke(() =>
        {
            UiError.Text = L.T("Интерфейс агента не загрузился. Обратитесь к администратору клуба.");
            SendI18n();
            RefreshServerTexts();
        });

        /// <summary>Тексты интерфейса на текущем языке (с английским там, где перевода нет) и языки клуба.</summary>
        private void SendI18n()
        {
            var locale = L.Current;
            SendMessageToWeb(JsonSerializer.Serialize(new
            {
                type = "i18n",
                locale,
                lines = L.LinesFor(locale),
                languages = L.Languages.Select(language => new { code = language.Code, name = language.Name })
            }));
        }

        /// <summary>Язык клуба и правки переводов с сервера (из heartbeat).</summary>
        private void ApplyClubLanguage(HeartbeatResponse hb)
        {
            if (!hb.ClubReported) return;
            if (hb.ClubLanguages is { Count: > 0 } languages) L.SetServerLanguages(languages);
            if (!string.IsNullOrEmpty(hb.ClubLocale))
            {
                L.SetClubLocale(hb.ClubLocale);
                if (_cfgService.Load().ClubLocale != hb.ClubLocale) _cfgService.Update(cfg => cfg.ClubLocale = hb.ClubLocale!);
            }

            if (hb.TranslationsVersion is { } version)
            {
                _translationsVersion = version;
                RefreshServerTexts();
            }
        }

        // Правки суперадмина: загружаются, только когда версия переводов на сервере новее загруженной.
        private async void RefreshServerTexts()
        {
            var locale = L.Current;
            if (_translationsVersion is not { } version || L.ServerVersion(locale) == version || _textsLoading) return;

            _textsLoading = true;
            try
            {
                var pack = await _zafiroApi.GetAgentTranslationsAsync(locale, CancellationToken.None);
                if (pack is not null) L.SetServerLines(locale, pack.Version ?? version, pack.Lines);
            }
            catch (Exception ex)
            {
                SafeLog.Write("[I18n] server texts were not loaded: " + ex.Message);
            }
            finally
            {
                _textsLoading = false;
            }
        }

        private void StopHeartbeat()
        {
            _heartbeatService?.Dispose();
            _heartbeatService = null;
        }

#if DEBUG
        private void MainWindow_KeyDown(object sender, System.Windows.Input.KeyEventArgs e)
        {
            try
            {
                if (e.Key == System.Windows.Input.Key.F12)
                    WebView.CoreWebView2?.OpenDevToolsWindow();
            }
            catch (Exception ex)
            {
                Debug.WriteLine("[DevTools] failed to open: " + ex);
            }
        }
#endif

        /// <summary>Браузер WebView2 завершился: элемент создаётся заново (не чаще раза в 10 с).</summary>
        private async Task RecreateWebViewAsync()
        {
            if (DateTime.UtcNow - _webViewRecreatedAtUtc < TimeSpan.FromSeconds(10) || WebView.Parent is not System.Windows.Controls.Panel panel) return;
            _webViewRecreatedAtUtc = DateTime.UtcNow;

            var index = panel.Children.IndexOf(WebView);
            panel.Children.Remove(WebView);
            try { WebView.Dispose(); }
            catch (Exception ex) { SafeLog.Write("[WebView] dispose failed: " + ex.Message); }

            WebView = new Microsoft.Web.WebView2.Wpf.WebView2();
            panel.Children.Insert(index, WebView);
            try
            {
                await InitializeWebViewAsync();
            }
            catch (Exception ex)
            {
                SafeLog.Write("[WebView] could not restart: " + ex.Message);
                WebView.Visibility = Visibility.Collapsed;
                UiError.Visibility = Visibility.Visible;
            }
        }

        /// <summary>
        /// Данные WebView2 — в профиле пользователя: рядом с exe (Program Files) писать нельзя.
        /// Клиент может подменить папку файлом или ссылкой — тогда она пересоздаётся, а если
        /// не выходит, берётся новая временная папка.
        /// </summary>
        private static string WebViewDataFolder()
        {
            var folder = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "ZafiroAgent", "WebView2");
            try
            {
                if (File.Exists(folder)) File.Delete(folder);
                var dir = new DirectoryInfo(folder);
                if (dir.Exists && (dir.Attributes & FileAttributes.ReparsePoint) != 0) dir.Delete();
                Directory.CreateDirectory(folder);
                return folder;
            }
            catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
            {
                SafeLog.Write("[WebView] data folder is unusable, using a temporary one: " + ex.Message);
                return Path.Combine(Path.GetTempPath(), "ZafiroAgent-WebView2-" + Guid.NewGuid().ToString("N"));
            }
        }

        private async Task InitializeWebViewAsync()
        {
            var environment = await CoreWebView2Environment.CreateAsync(null, WebViewDataFolder());
            await WebView.EnsureCoreWebView2Async(environment);

            var core = WebView.CoreWebView2;
            core.Settings.AreDefaultContextMenusEnabled = false;
            core.Settings.IsStatusBarEnabled = false;
            // Ctrl+колесо, жест щипка и свайп назад ломали бы вёрстку экрана блокировки.
            core.Settings.IsZoomControlEnabled = false;
            core.Settings.IsPinchZoomEnabled = false;
            core.Settings.IsSwipeNavigationEnabled = false;
#if DEBUG
            core.Settings.AreDevToolsEnabled = true;
#else
            // Без консоли и горячих клавиш браузера клиент не может слать агенту свои команды.
            core.Settings.AreDevToolsEnabled = false;
            core.Settings.AreBrowserAcceleratorKeysEnabled = false;
#endif
            core.NavigationStarting += (_, args) =>
            {
                if (!IsAppUrl(args.Uri) && !(_showingDiagnosticPage && args.Uri.StartsWith("data:", StringComparison.Ordinal)))
                    args.Cancel = true;
            };
            core.NewWindowRequested += (_, args) => args.Handled = true;
            core.ProcessFailed += (_, args) =>
            {
                // Клиент может завершить процессы msedgewebview2.exe: без этого остался бы пустой экран.
                SafeLog.Write("[WebView] process failed: " + args.ProcessFailedKind);
                if (args.ProcessFailedKind == CoreWebView2ProcessFailedKind.BrowserProcessExited)
                    Dispatcher.BeginInvoke(() => _ = RecreateWebViewAsync());
                else
                    Dispatcher.BeginInvoke(() =>
                    {
                        try { WebView.CoreWebView2?.Reload(); }
                        catch (Exception ex) { SafeLog.Write("[WebView] reload failed: " + ex.Message); }
                    });
            };
            core.WebMessageReceived += CoreWebView2_WebMessageReceived;

            var frontendFolder = Path.Combine(AppContext.BaseDirectory, "Frontend");
            var indexPath = Path.Combine(frontendFolder, "index.html");
            if (!File.Exists(indexPath))
            {
                _showingDiagnosticPage = true;
                WebView.NavigateToString("<html><body style=\"background:#0B0D12;color:#F3F4F6;font-family:Segoe UI;padding:30px\">" +
                    $"<h2>UI не найден</h2><p>Ожидаемый путь:</p><pre style=\"color:#9299AA\">{System.Net.WebUtility.HtmlEncode(indexPath)}</pre></body></html>");
                return;
            }

            core.SetVirtualHostNameToFolderMapping(AppHost, frontendFolder, CoreWebView2HostResourceAccessKind.Allow);
            core.NavigationCompleted += (_, _) =>
            {
                SendI18n();
                var cfg = _cfgService.Load();
                SendMessageToWeb(JsonSerializer.Serialize(new
                {
                    type = "clubInfo",
                    clubName = cfg.ClubName,
                    number = string.IsNullOrWhiteSpace(cfg.ComputerNumber) ? null : cfg.ComputerNumber,
                    zone = string.IsNullOrWhiteSpace(cfg.Zone) ? null : cfg.Zone
                }));
            };
            core.Navigate(AppOrigin + "index.html");
        }

        private static bool IsAppUrl(string? url) =>
            url is not null && url.StartsWith(AppOrigin, StringComparison.OrdinalIgnoreCase);

        private static object SessionPayload(SessionInfo session) => new
        {
            sessionId = session.SessionId,
            userName = session.UserName,
            balance = session.Balance,
            bonus = session.BonusBalance,
            tariff = session.TariffName,
            startedAt = session.StartedAt?.ToString("o"),
            remainingMinutes = session.Remaining?.TotalMinutes
        };

        private async Task SendSessionDataAsync(string sessionId)
        {
            try
            {
                var session = await _apiService.GetSessionInfoAsync(sessionId, CancellationToken.None);
                SendMessageToWeb(JsonSerializer.Serialize(new { type = "sessionData", session = SessionPayload(session) }));
            }
            catch (HttpRequestException ex)
            {
                var message = ex.StatusCode is { } statusCode
                    ? L.T("Не удалось загрузить баланс с сервера (:code).", ("code", (int)statusCode))
                    : L.T("Не удалось подключиться к серверу баланса.");
                SendMessageToWeb(JsonSerializer.Serialize(new { type = "sessionData", error = true, message }));
            }
            catch
            {
                SendMessageToWeb(JsonSerializer.Serialize(new { type = "sessionData", error = true, message = L.T("Не удалось загрузить баланс.") }));
            }

            // Профиль не знает о сессии ПК: её состояние присылаем отдельно.
            if (_activeSessionId is not null) AdoptSession(_activeSessionId, _activeSessionEndsAt);
        }

        private async Task SendTariffsAsync()
        {
            try
            {
                var list = await _apiService.GetTariffsAsync(CancellationToken.None);
                SendMessageToWeb(JsonSerializer.Serialize(new { type = "tariffs", list }));
            }
            catch
            {
                SendMessageToWeb(JsonSerializer.Serialize(new { type = "tariffs", list = Array.Empty<object>(), error = true, message = L.T("Не удалось загрузить тарифы. Проверьте подключение.") }));
            }
        }

        private async Task HandleEndSessionAsync()
        {
            try
            {
                var sessionId = _activeSessionId;
                if (sessionId is null)
                {
                    SendMessageToWeb("{\"type\":\"sessionEnded\",\"reason\":\"none\"}");
                    return;
                }

                var settled = await _apiService.EndSessionWithResultAsync(sessionId, CancellationToken.None);
                if (settled is null)
                {
                    SendMessageToWeb(JsonSerializer.Serialize(new { type = "actionFailed", message = L.T("Не удалось завершить сессию.") }));
                    return;
                }

                // Пользователь остаётся в системе: токен и клуб не трогаем.
                EndSessionLocally("ended");
                // Что вернулось на баланс и что сохранилось абонементом — странице для уведомления.
                SendMessageToWeb(JsonSerializer.Serialize(new { type = "sessionSettled", refunded = settled.Refunded, refundedBonus = settled.RefundedBonus, keptMinutes = settled.KeptMinutes }));
            }
            catch (Exception ex)
            {
                Debug.WriteLine("[EndSession] failed: " + ex.Message);
                SendMessageToWeb(JsonSerializer.Serialize(new { type = "actionFailed", message = L.T("Не удалось завершить сессию.") }));
            }
        }

        private void SendGuestFeatures()
        {
            if (_guestFeatures is { } features)
                SendMessageToWeb(JsonSerializer.Serialize(new { type = "guestFeatures", features, idleLogoutMinutes = _idleLogoutMinutes }));
        }

        /// <summary>Запрос /api/guest/… от имени клиента; ответ сервера уходит странице как type.</summary>
        private async Task<GuestApiResult> GuestCallAsync(string replyType, HttpMethod method, string path, object? body = null, bool fromThisComputer = false)
        {
            var result = await _apiService.CallGuestApiAsync(method, path, body, fromThisComputer, CancellationToken.None);
            SendMessageToWeb(JsonSerializer.Serialize(new { type = replyType, ok = result.Success, message = result.Message, data = result.Data }));
            return result;
        }

        /// <summary>Выбор клиента на странице: main или bonus; иначе null (сервер возьмёт как раньше).</summary>
        private static string? PaymentBalance(JsonElement message) =>
            message.TryGetProperty("balance", out var b) && b.ValueKind == JsonValueKind.String && b.GetString() is "main" or "bonus" ? b.GetString() : null;

        private static long? FriendshipId(JsonElement message) =>
            message.TryGetProperty("id", out var id) && id.ValueKind == JsonValueKind.Number && id.TryGetInt64(out var value) && value > 0 ? value : null;

        private async Task HandleLogoutAsync(string? notice = null)
        {
            if (_authPromptOpen) return;
            _authPromptOpen = true;

            try
            {
                // Статус locked отправляется, пока токен ещё есть.
                if (_heartbeatService is { } heartbeat)
                {
                    await heartbeat.StopAsync(ComputerState.Locked, TimeSpan.FromSeconds(3));
                    heartbeat.Dispose();
                    _heartbeatService = null;
                }

                await _clubSetup.SignOutAsync(TimeSpan.FromSeconds(3));
                SendMessageToWeb("{\"type\":\"loggedOut\"}");

                // Следующий клиент видит язык клуба, а не язык предыдущего.
                L.SetUserLocale(null);

                // Клуб и ключ установки сохраняются: нужен только повторный вход пользователя.
                if (ShowSignIn(notice))
                {
                    StartHeartbeat();
                }
                else
                {
                    Close();
                }
            }
            finally
            {
                _authPromptOpen = false;
            }
        }

        private async void CoreWebView2_WebMessageReceived(object? sender, CoreWebView2WebMessageReceivedEventArgs e)
        {
            // Команды принимаются только от собственного интерфейса агента.
            if (!IsAppUrl(e.Source))
            {
                Debug.WriteLine("[WebMsg] ignored message from " + e.Source);
                return;
            }

            try
            {
                string? json = null;
                try
                {
                    json = e.WebMessageAsJson;
                    Debug.WriteLine("[WebMsg Received] " + MessageType(json));
                }
                catch (Exception exGet)
                {
                    Debug.WriteLine("[WebMsg] cannot read WebMessageAsJson: " + exGet);
                }

                if (string.IsNullOrWhiteSpace(json))
                {
                    Debug.WriteLine("[WebMsg] empty payload");
                    throw new InvalidOperationException("empty message payload");
                }

                using var doc = JsonDocument.Parse(json);
                if (!doc.RootElement.TryGetProperty("type", out var t)) return;
                var type = t.GetString();
                // Вход — только в окне агента (ClubSetupWindow): страница не принимает пароль и не меняет аккаунт.
                if (type == "refreshBalance")
                {
                    await RefreshBalanceAsync(manual: true);
                }
                else if (type == "requestGuestFeatures")
                {
                    SendGuestFeatures();
                }
                else if (type == "requestFriends")
                {
                    await GuestCallAsync("friends", HttpMethod.Get, "friends");
                }
                else if (type == "addFriend")
                {
                    var phone = doc.RootElement.TryGetProperty("phone", out var p) && p.ValueKind == JsonValueKind.String ? p.GetString() : null;
                    await GuestCallAsync("friends", HttpMethod.Post, "friends", new { phone = phone ?? string.Empty });
                }
                else if (type is "acceptFriend" or "removeFriend")
                {
                    if (FriendshipId(doc.RootElement) is not { } id) return;
                    await GuestCallAsync("friends", HttpMethod.Post, $"friends/{id}/{(type == "acceptFriend" ? "accept" : "remove")}");
                }
                else if (type == "transferBalance")
                {
                    if (FriendshipId(doc.RootElement) is not { } id) return;
                    var amount = doc.RootElement.TryGetProperty("amount", out var a) && a.ValueKind == JsonValueKind.String ? a.GetString() : null;
                    // Пароль подтверждает перевод: только в теле запроса к серверу, не в журнал и не в настройки.
                    var password = doc.RootElement.TryGetProperty("password", out var pw) && pw.ValueKind == JsonValueKind.String ? pw.GetString() : null;
                    if (!string.IsNullOrEmpty(password)) SafeLog.Register(password);
                    await GuestCallAsync("transferResult", HttpMethod.Post, $"friends/{id}/transfer", new { amount = amount ?? string.Empty, password = password ?? string.Empty });
                }
                else if (type == "requestChat")
                {
                    var after = doc.RootElement.TryGetProperty("after", out var a) && a.ValueKind == JsonValueKind.Number && a.TryGetInt64(out var id) && id > 0 ? id : 0;
                    await GuestCallAsync("chat", HttpMethod.Get, after > 0 ? $"chat?after={after.ToString(System.Globalization.CultureInfo.InvariantCulture)}" : "chat");
                }
                else if (type == "sendChat")
                {
                    var body = doc.RootElement.TryGetProperty("body", out var b) && b.ValueKind == JsonValueKind.String ? b.GetString() : null;
                    if (string.IsNullOrWhiteSpace(body)) return;
                    await GuestCallAsync("chatSent", HttpMethod.Post, "chat", new { body }, fromThisComputer: true);
                }
                else if (type == "requestNews")
                {
                    await GuestCallAsync("news", HttpMethod.Get, "news");
                }
                else if (type == "requestMinutes")
                {
                    await GuestCallAsync("minutes", HttpMethod.Get, "minutes", fromThisComputer: true);
                }
                else if (type == "purchaseMinutes")
                {
                    // Как с тарифом: второе нажатие, пока покупка идёт, не списывает деньги ещё раз.
                    if (_purchaseInFlight) return;
                    if (!doc.RootElement.TryGetProperty("minutes", out var m) || m.ValueKind != JsonValueKind.Number || !m.TryGetInt32(out var minutes) || minutes <= 0) return;
                    var payWith = PaymentBalance(doc.RootElement);
                    _purchaseInFlight = true;
                    try
                    {
                        object body = payWith is null ? new { minutes } : new { minutes, balance = payWith };
                        var result = await _apiService.CallGuestApiAsync(HttpMethod.Post, "minutes/purchase", body, true, CancellationToken.None);
                        var data = result.Data;
                        string? Text(string name) => data is { } d && d.TryGetProperty(name, out var v) && v.ValueKind == JsonValueKind.String ? v.GetString() : null;
                        var sessionId = Text("session_id");
                        DateTimeOffset? endsAt = DateTimeOffset.TryParse(Text("session_ends_at"), System.Globalization.CultureInfo.InvariantCulture, System.Globalization.DateTimeStyles.AssumeUniversal, out var ends) ? ends.ToUniversalTime() : null;
                        if (result.Success && !string.IsNullOrWhiteSpace(sessionId))
                            AdoptSession(sessionId, endsAt);

                        SendMessageToWeb(JsonSerializer.Serialize(new
                        {
                            type = "purchaseResult",
                            success = result.Success,
                            message = result.Message,
                            sessionId,
                            newBalance = Text("new_balance"),
                            newBonus = Text("new_bonus"),
                            sessionEndsAt = endsAt?.ToString("O")
                        }));
                    }
                    finally
                    {
                        _purchaseInFlight = false;
                    }
                }
                else if (type == "requestPackages")
                {
                    await GuestCallAsync("packages", HttpMethod.Get, "packages");
                }
                else if (type == "startPackage")
                {
                    // Абонемент (сохранённое время тарифа): запуск без оплаты, как покупка — сессия начинается здесь.
                    if (_purchaseInFlight) return;
                    if (!doc.RootElement.TryGetProperty("packageId", out var pid) || pid.ValueKind != JsonValueKind.String ||
                        !long.TryParse(pid.GetString(), System.Globalization.NumberStyles.None, System.Globalization.CultureInfo.InvariantCulture, out var packageId) || packageId <= 0) return;
                    _purchaseInFlight = true;
                    try
                    {
                        var result = await _apiService.CallGuestApiAsync(HttpMethod.Post, "packages/" + packageId.ToString(System.Globalization.CultureInfo.InvariantCulture) + "/start", new { }, true, CancellationToken.None);
                        var data = result.Data;
                        string? Text(string name) => data is { } d && d.TryGetProperty(name, out var v) && v.ValueKind == JsonValueKind.String ? v.GetString() : null;
                        var sessionId = Text("session_id");
                        DateTimeOffset? endsAt = DateTimeOffset.TryParse(Text("session_ends_at"), System.Globalization.CultureInfo.InvariantCulture, System.Globalization.DateTimeStyles.AssumeUniversal, out var ends) ? ends.ToUniversalTime() : null;
                        if (result.Success && !string.IsNullOrWhiteSpace(sessionId))
                            AdoptSession(sessionId, endsAt);

                        SendMessageToWeb(JsonSerializer.Serialize(new
                        {
                            type = "purchaseResult",
                            success = result.Success,
                            message = result.Message,
                            sessionId,
                            newBalance = Text("new_balance"),
                            newBonus = Text("new_bonus"),
                            sessionEndsAt = endsAt?.ToString("O")
                        }));
                    }
                    finally
                    {
                        _purchaseInFlight = false;
                    }
                }
                else if (type == "requestHistory")
                {
                    // Страница «Показать ещё» передаёт id последней показанной записи.
                    var before = doc.RootElement.TryGetProperty("before", out var b) && b.ValueKind == JsonValueKind.Number && b.TryGetInt64(out var id) && id > 0 ? id : (long?)null;
                    // Фильтр — только из известного списка: значение попадает в адрес запроса.
                    var filter = doc.RootElement.TryGetProperty("filter", out var f) && f.ValueKind == JsonValueKind.String && f.GetString() is "purchases" or "top_ups" or "transfers" or "visits" ? f.GetString() : null;
                    var query = new List<string>();
                    if (before is { } from) query.Add("before=" + from.ToString(System.Globalization.CultureInfo.InvariantCulture));
                    if (filter is not null) query.Add("filter=" + filter);
                    await GuestCallAsync("history", HttpMethod.Get, query.Count > 0 ? "history?" + string.Join("&", query) : "history");
                }
                else if (type == "purchaseProducts")
                {
                    // Как с тарифом: второе нажатие, пока заказ оформляется, не списывает деньги ещё раз.
                    if (_purchaseInFlight) return;
                    if (!doc.RootElement.TryGetProperty("items", out var list) || list.ValueKind != JsonValueKind.Array) return;
                    var items = new List<object>();
                    foreach (var item in list.EnumerateArray())
                    {
                        if (item.ValueKind != JsonValueKind.Object ||
                            !item.TryGetProperty("id", out var pid) || !pid.TryGetInt64(out var productId) || productId <= 0 ||
                            !item.TryGetProperty("quantity", out var q) || !q.TryGetInt32(out var quantity) || quantity <= 0)
                            return;
                        items.Add(new { id = productId, quantity });
                    }
                    if (items.Count == 0) return;
                    _purchaseInFlight = true;
                    try
                    {
                        await GuestCallAsync("shopResult", HttpMethod.Post, "shop/purchase", new { items }, fromThisComputer: true);
                    }
                    finally
                    {
                        _purchaseInFlight = false;
                    }
                }
                else if (type == "requestSeats")
                {
                    await GuestCallAsync("seats", HttpMethod.Get, "seats", fromThisComputer: true);
                }
                else if (type == "transferSeat")
                {
                    if (!doc.RootElement.TryGetProperty("computerId", out var c) || c.ValueKind != JsonValueKind.Number || !c.TryGetInt64(out var target) || target <= 0) return;
                    var result = await GuestCallAsync("seatResult", HttpMethod.Post, "seat-transfer", new { target_computer_id = target }, fromThisComputer: true);
                    if (result.Success)
                    {
                        // Сессия теперь на другом ПК: здесь клиент выходит, там входит своей учётной записью.
                        EndSessionLocally("transferred");
                        var notice = result.Message;
                        _ = Dispatcher.InvokeAsync(() => HandleLogoutAsync(notice));
                    }
                }
                else if (type == "requestSession")
                {
                    await SendSessionDataAsync(_activeSessionId ?? string.Empty);
                }
                else if (type == "requestTariffs")
                {
                    await SendTariffsAsync();
                }
                else if (type == "requestI18n")
                {
                    SendI18n();
                }
                else if (type == "setLanguage")
                {
                    // Только из списка языков клуба; ответ — сообщение i18n (через L.Changed).
                    if (doc.RootElement.TryGetProperty("locale", out var requested) && requested.ValueKind == JsonValueKind.String)
                        L.SetUserLocale(requested.GetString());
                }
                else if (type == "requestProducts")
                {
                    try
                    {
                        var list = await _apiService.GetProductsAsync(CancellationToken.None);
                        SendMessageToWeb(JsonSerializer.Serialize(new { type = "products", list }));
                    }
                    catch (Exception ex)
                    {
                        Debug.WriteLine("[Error] failed to get products: " + ex.Message);
                        SendMessageToWeb(JsonSerializer.Serialize(new { type = "products", list = Array.Empty<Product>(), error = true }));
                    }
                }
                else if (type == "purchaseTariff")
                {
                    // Второе нажатие, пока первая покупка не завершилась, не списывает деньги ещё раз.
                    if (_purchaseInFlight) return;
                    _purchaseInFlight = true;
                    try
                    {
                        var tariffId = doc.RootElement.GetProperty("tariffId").GetString() ?? string.Empty;
                        var res = await _apiService.PurchaseTariffAsync(tariffId, PaymentBalance(doc.RootElement), CancellationToken.None);

                        if (res.Success && !string.IsNullOrWhiteSpace(res.SessionId))
                            AdoptSession(res.SessionId, res.SessionEndsAt);

                        SendMessageToWeb(JsonSerializer.Serialize(new
                        {
                            type = "purchaseResult",
                            success = res.Success,
                            message = res.Message,
                            sessionId = res.SessionId,
                            newBalance = res.NewBalance,
                            newBonus = res.NewBonus,
                            minutesAdded = res.MinutesAdded,
                            sessionEndsAt = res.SessionEndsAt?.ToUniversalTime().ToString("O")
                        }));
                    }
                    catch
                    {
                        Debug.WriteLine("[Error] purchase failed");
                        SendMessageToWeb(JsonSerializer.Serialize(new { type = "purchaseResult", success = false, message = L.T("Ошибка покупки") }));
                    }
                    finally
                    {
                        _purchaseInFlight = false;
                    }
                }
                else if (type == "endSession")
                {
                    await HandleEndSessionAsync();
                }
                else if (type == "logout")
                {
                    // Окно авторизации открываем вне обработчика сообщения WebView.
                    _ = Dispatcher.InvokeAsync(() => HandleLogoutAsync());
                }
                else if (type == "returnToDesktop")
                {
                    // Рабочий стол — только во время оплаченной сессии.
                    if (_activeSessionId is null)
                    {
                        SendMessageToWeb(JsonSerializer.Serialize(new { type = "actionFailed", message = L.T("Сначала выберите тариф: без активной сессии рабочий стол недоступен.") }));
                        return;
                    }

                    Dispatcher.Invoke(MinimizeToTray);
                    SendMessageToWeb("{\"type\":\"returned\"}");
                }
            }
            catch (Exception ex)
            {
                Debug.WriteLine("[WebMsg] failed to parse/process incoming message: " + ex);
                try { SendMessageToWeb("{\"type\":\"error\",\"message\":\"invalid_message\"}"); } catch (Exception ex2) { Debug.WriteLine("[WebMsg] failed to send error message: " + ex2); }
            }
        }

        private static string MessageType(string? json) =>
            json is not null && System.Text.RegularExpressions.Regex.Match(json, "\"type\"\\s*:\\s*\"([a-zA-Z]+)\"") is { Success: true } m ? m.Groups[1].Value : "?";

        private void SendMessageToWeb(string message)
        {
            // PostWebMessageAsJson допустим только из UI-потока, а heartbeat работает в фоновом.
            if (!Dispatcher.CheckAccess())
            {
                Dispatcher.BeginInvoke(new Action(() => SendMessageToWeb(message)));
                return;
            }

            try
            {
                // В журнал — только тип сообщения: heartbeat каждые 30 с и списки товаров раздували бы лог.
                if (!message.Contains("\"type\":\"heartbeat\"", StringComparison.Ordinal))
                    Debug.WriteLine("[PostToWebView] " + MessageType(message));
                WebView.CoreWebView2?.PostWebMessageAsJson(message);
            }
            catch (Exception ex)
            {
                Debug.WriteLine("[PostToWebView] error: " + ex.Message);
            }
        }
    }
}
