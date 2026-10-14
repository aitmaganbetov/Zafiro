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
        private DateTime _sessionAdoptedAtUtc;

        // Часы сервера минус часы ПК: если часы ПК спешат или отстают, сессия всё равно
        // заканчивается вовремя, а не раньше (и игра не сворачивается каждые 5 секунд).
        private TimeSpan _clockOffset;
        private bool _purchaseInFlight;
        private DateTime _webViewRecreatedAtUtc;

        private DateTimeOffset ServerNow => DateTimeOffset.UtcNow + _clockOffset;

        [DllImport("shell32.dll")]
        private static extern void SHMinimizeAll();

        public MainWindow(ConfigurationService config, ClubSetupService clubSetup, ZafiroApiClient api)
        {
            _cfgService = config;
            _clubSetup = clubSetup;
            _zafiroApi = api;
            InitializeComponent();
            InitializeTrayIcon();
            Loaded += MainWindow_Loaded;
#if DEBUG
            KeyDown += MainWindow_KeyDown;
#endif
            _sessionTimer.Tick += (_, _) => CheckSessionTime();
        }

        private void InitializeTrayIcon()
        {
            // Выход — только по PIN-коду сотрудника (в режиме киоска).
            _trayIcon = new TrayIcon("Zafiro Agent", ("Открыть Zafiro", RestoreFromTray), ("Выход", RequestExit));
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
            _sessionTimer.Stop();

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
                        if (hb.ClubReported) App.Instance.Covers.SetBranding(hb.ClubName, hb.ClubLogoUrl);
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

        /// <summary>Сессия закончилась: агент снова поверх рабочего стола и ждёт оплаты.</summary>
        private void EndSessionLocally(string reason)
        {
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

            // Следующий клиент не должен оказаться в чужом аккаунте и тратить чужой баланс.
            Dispatcher.InvokeAsync(() => HandleLogoutAsync("Сессия завершена. Войдите, чтобы продолжить."));
        }

        private void CheckSessionTime()
        {
            App.Instance.RefreshKiosk(); // киоск включается, как только ПК получил токен устройства
            if (_activeSessionId is not null && _activeSessionEndsAt is { } endsAt && endsAt <= ServerNow)
                EndSessionLocally("expired");
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
                    ? $"Не удалось загрузить баланс с сервера ({(int)statusCode})."
                    : "Не удалось подключиться к серверу баланса.";
                SendMessageToWeb(JsonSerializer.Serialize(new { type = "sessionData", error = true, message }));
            }
            catch
            {
                SendMessageToWeb(JsonSerializer.Serialize(new { type = "sessionData", error = true, message = "Не удалось загрузить баланс." }));
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
                SendMessageToWeb("{\"type\":\"tariffs\",\"list\":[],\"error\":true,\"message\":\"Не удалось загрузить тарифы. Проверьте подключение.\"}");
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

                if (!await _apiService.EndSessionAsync(sessionId, CancellationToken.None))
                {
                    SendMessageToWeb(JsonSerializer.Serialize(new { type = "actionFailed", message = "Не удалось завершить сессию." }));
                    return;
                }

                // Клиент выходит из аккаунта (EndSessionLocally), клуб и токен ПК остаются.
                EndSessionLocally("ended");
            }
            catch (Exception ex)
            {
                Debug.WriteLine("[EndSession] failed: " + ex.Message);
                SendMessageToWeb(JsonSerializer.Serialize(new { type = "actionFailed", message = "Не удалось завершить сессию." }));
            }
        }

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
                if (type == "requestSession")
                {
                    await SendSessionDataAsync(_activeSessionId ?? string.Empty);
                }
                else if (type == "requestTariffs")
                {
                    await SendTariffsAsync();
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
                        var res = await _apiService.PurchaseTariffAsync(tariffId, CancellationToken.None);

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
                        SendMessageToWeb("{\"type\":\"purchaseResult\",\"success\":false,\"message\":\"Ошибка покупки\"}");
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
                        SendMessageToWeb(JsonSerializer.Serialize(new { type = "actionFailed", message = "Сначала выберите тариф: без активной сессии рабочий стол недоступен." }));
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
