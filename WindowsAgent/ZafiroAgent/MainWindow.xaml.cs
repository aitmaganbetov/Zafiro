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
using ZafiroAgent.ViewModels;

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
        private readonly SessionViewModel _sessionViewModel = new();
        private readonly DispatcherTimer _sessionTimer = new() { Interval = TimeSpan.FromSeconds(5) };
        private HeartbeatService? _heartbeatService;
        private System.Windows.Forms.NotifyIcon? _trayIcon;
        private bool _authPromptOpen;
        private bool _closeFlushed;
        private bool _reloginTried;
        private bool _showingDiagnosticPage;

        // Оплаченная сессия на этом ПК (по данным сервера). Без неё рабочий стол недоступен.
        private string? _activeSessionId;
        private DateTimeOffset? _activeSessionEndsAt;
        private DateTime _sessionAdoptedAtUtc;

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
            var menu = new System.Windows.Forms.ContextMenuStrip();
            menu.Items.Add("Открыть Zafiro", null, (_, _) => RestoreFromTray());
            menu.Items.Add("Выход", null, (_, _) => Close());

            _trayIcon = new System.Windows.Forms.NotifyIcon
            {
                Icon = System.Drawing.SystemIcons.Application,
                Text = "Zafiro Agent",
                ContextMenuStrip = menu,
                Visible = true
            };
            _trayIcon.DoubleClick += (_, _) => RestoreFromTray();
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
            Activate();
        }

        // Финальный статус отправляется асинхронно: закрытие не блокирует UI-поток.
        protected override void OnClosing(System.ComponentModel.CancelEventArgs e)
        {
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
                _sessionViewModel.EndSession();
                _clubSetup.Logout();
            }
            catch (Exception ex)
            {
                SafeLog.Write("[Shutdown] logout failed: " + ex.Message);
            }

            StopHeartbeat();

            if (_trayIcon is not null)
            {
                _trayIcon.Visible = false;
                _trayIcon.Dispose();
            }

            base.OnClosed(e);
        }

        private async void MainWindow_Loaded(object? sender, RoutedEventArgs e)
        {
            // Вход в клуб уже проверен в App до открытия этого окна.
            StartHeartbeat();
            _sessionTimer.Start();

            await InitializeWebViewAsync();
        }

        // Повторный вход поверх агента: отдельной кнопки на панели задач у окна нет.
        private bool ShowSignIn()
        {
            var window = new Views.ClubSetupWindow(_clubSetup) { Owner = this, ShowInTaskbar = false };
            return window.ShowDialog() == true && window.Session is not null;
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
                        number = hb.Number,
                        zone = hb.Zone
                    };
                    SendMessageToWeb(JsonSerializer.Serialize(payload));
                    Dispatcher.BeginInvoke(() => SyncSessionWithServer(hb));
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
            _sessionViewModel.StartGameSession(sessionId, endsAtUtc ?? DateTimeOffset.UtcNow);
            _heartbeatService?.SetState(ComputerState.InSession, sessionId);
            _heartbeatService?.SetSessionEnd(endsAtUtc);
            SendMessageToWeb(JsonSerializer.Serialize(new
            {
                type = "sessionSync",
                sessionId,
                sessionEndsAt = endsAtUtc?.ToUniversalTime().ToString("O")
            }));
        }

        /// <summary>Сессия закончилась: агент снова поверх рабочего стола и ждёт оплаты.</summary>
        private void EndSessionLocally(string reason)
        {
            _activeSessionId = null;
            _activeSessionEndsAt = null;
            _sessionViewModel.EndActiveSession();
            _heartbeatService?.SetState(ComputerState.Idle);
            SendMessageToWeb(JsonSerializer.Serialize(new { type = "sessionEnded", reason }));
            RestoreFromTray();
        }

        private void CheckSessionTime()
        {
            if (_activeSessionId is not null && _activeSessionEndsAt is { } endsAt && endsAt <= DateTimeOffset.UtcNow)
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

        private async Task InitializeWebViewAsync()
        {
            // Данные WebView2 — в профиле пользователя: рядом с exe (Program Files) писать нельзя.
            var dataFolder = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "ZafiroAgent", "WebView2");
            var environment = await CoreWebView2Environment.CreateAsync(null, dataFolder);
            await WebView.EnsureCoreWebView2Async(environment);

            var core = WebView.CoreWebView2;
            core.Settings.AreDefaultContextMenusEnabled = false;
            core.Settings.IsStatusBarEnabled = false;
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
            core.NavigationCompleted += (_, _) => SendMessageToWeb("{\"type\":\"pong\"}");
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

        private async Task HandleUserLoginAsync(string login, string password)
        {
            try
            {
                var result = await _apiService.AuthenticateAsync(login, password, CancellationToken.None);
                if (!result.Success)
                {
                    SendMessageToWeb(JsonSerializer.Serialize(new { type = "authFailed", message = result.Message ?? "Не удалось выполнить вход." }));
                    return;
                }

                if (!string.IsNullOrWhiteSpace(result.Token))
                {
                    _cfgService.Update(cfg =>
                    {
                        cfg.ProtectedApiToken = new SecureStorageService().Protect(result.Token);
                        cfg.TokenExpiresAtUtc = result.ExpiresAt ?? DateTime.UtcNow.AddHours(2);
                    });
                }

                _sessionViewModel.StartSession(result);
                // Вход не отменяет оплаченную сессию: её состояние определяет сервер.
                if (_activeSessionId is null) _heartbeatService?.SetState(ComputerState.Idle);
                if (!string.IsNullOrWhiteSpace(result.Token) || !string.IsNullOrWhiteSpace(result.SessionId))
                {
                    await SendSessionDataAsync(result.SessionId ?? string.Empty);
                    await SendTariffsAsync();
                }

                SendMessageToWeb(JsonSerializer.Serialize(new { type = "authSuccess", userName = result.UserName ?? login }));
            }
            catch (Exception ex)
            {
                Debug.WriteLine("[UserLogin] failed: " + ex.Message);
                SendMessageToWeb("{\"type\":\"authFailed\",\"message\":\"Не удалось выполнить вход.\"}");
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

                // Пользователь остаётся в системе: токен и клуб не трогаем.
                EndSessionLocally("ended");
            }
            catch (Exception ex)
            {
                Debug.WriteLine("[EndSession] failed: " + ex.Message);
                SendMessageToWeb(JsonSerializer.Serialize(new { type = "actionFailed", message = "Не удалось завершить сессию." }));
            }
        }

        private async Task HandleLogoutAsync()
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

                _sessionViewModel.EndSession();
                _clubSetup.Logout();
                SendMessageToWeb("{\"type\":\"loggedOut\"}");

                // Клуб и ключ установки сохраняются: нужен только повторный вход пользователя.
                if (ShowSignIn())
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
                    Debug.WriteLine("[WebMsg Received] " + json);
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
                if (type == "userLogin")
                {
                    var login = doc.RootElement.TryGetProperty("phone", out var phone) ? phone.GetString() ?? string.Empty : string.Empty;
                    var password = doc.RootElement.TryGetProperty("password", out var pass) ? pass.GetString() ?? string.Empty : string.Empty;
                    if (string.IsNullOrWhiteSpace(login) || string.IsNullOrWhiteSpace(password))
                    {
                        SendMessageToWeb("{\"type\":\"authFailed\",\"message\":\"Введите телефон и пароль.\"}");
                        return;
                    }

                    await HandleUserLoginAsync(login, password);
                }
                else if (type == "requestSession")
                {
                    await SendSessionDataAsync(_sessionViewModel.SessionId);
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
                }
                else if (type == "endSession")
                {
                    await HandleEndSessionAsync();
                }
                else if (type == "logout")
                {
                    // Окно авторизации открываем вне обработчика сообщения WebView.
                    _ = Dispatcher.InvokeAsync(HandleLogoutAsync);
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
                else if (type == "ping")
                {
                    SendMessageToWeb("{\"type\":\"pong\"}");
                }
            }
            catch (Exception ex)
            {
                Debug.WriteLine("[WebMsg] failed to parse/process incoming message: " + ex);
                try { SendMessageToWeb("{\"type\":\"error\",\"message\":\"invalid_message\"}"); } catch (Exception ex2) { Debug.WriteLine("[WebMsg] failed to send error message: " + ex2); }
            }
        }

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
                Debug.WriteLine("[PostToWebView] " + message);
                WebView.CoreWebView2?.PostWebMessageAsJson(message);
            }
            catch (Exception ex)
            {
                Debug.WriteLine("[PostToWebView] error: " + ex.Message);
            }
        }
    }
}
