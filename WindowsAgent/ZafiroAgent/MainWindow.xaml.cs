using Microsoft.Web.WebView2.Core;
using System;
using System.IO;
using System.Net.Http;
using System.Runtime.InteropServices;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
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
        private readonly IApiService _apiService = new HttpApiService();
        private readonly ZafiroApiClient _zafiroApi = new(ZafiroApiClient.CreateHttpClient());
        private ConfigurationService? _cfgService;
        private ClubSetupService? _clubSetup;
        private AuthenticationService? _authService;
        private LoginViewModel? _loginViewModel;
        private SessionViewModel? _sessionViewModel;
        private HeartbeatService? _heartbeatService;
        private System.Windows.Forms.NotifyIcon? _trayIcon;
        private bool _authPromptOpen;
        private bool _closeFlushed;
        private bool _reloginTried;

        [DllImport("shell32.dll")]
        private static extern void SHMinimizeAll();

        public MainWindow()
        {
            InitializeComponent();
            InitializeTrayIcon();
            Loaded += MainWindow_Loaded;
            KeyDown += MainWindow_KeyDown;
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

        protected override void OnClosed(EventArgs e)
        {
            try
            {
                _sessionViewModel?.EndSession();
                _clubSetup?.ResetClub();
            }
            catch (Exception ex)
            {
                SafeLog.Write("[Shutdown] hard logout failed: " + ex.Message);
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
            _cfgService = new ConfigurationService();
            _clubSetup = new ClubSetupService(_zafiroApi, _cfgService, new SecureStorageService());

            // Без подтверждённого клуба агент не запускается.
            if (!EnsureClubSession())
            {
                Close();
                return;
            }

            _authService = new AuthenticationService(_apiService);
            _loginViewModel = new LoginViewModel(_authService);
            _sessionViewModel = new SessionViewModel();

            _loginViewModel.OnAuthenticated += async result =>
            {
                Dispatcher.Invoke(() => _sessionViewModel?.StartSession(result));
                _heartbeatService?.SetState(ComputerState.Idle);
                await SendSessionDataAsync(result.SessionId ?? string.Empty);
                SendMessageToWeb(JsonSerializer.Serialize(new { type = "authSuccess", userName = result.UserName }));
            };

            StartHeartbeat();

            await InitializeWebViewAsync();
        }

        private bool EnsureClubSession()
        {
            var stored = _clubSetup!.LoadSession();
            if (stored.Success) return true;

            var notice = stored.Failure is ApiFailure.NotConfigured or ApiFailure.TokenExpired ? null : stored.Message;
            var window = new Views.ClubSetupWindow(_clubSetup, notice) { Owner = this };
            return window.ShowDialog() == true && window.Session is not null;
        }

        private void StartHeartbeat()
        {
            StopHeartbeat();
            try
            {
                var service = new HeartbeatService(_cfgService!, _clubSetup!, _zafiroApi);
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

        // 401: вход обновляется один раз; повторный 401 после нового входа цикл не запускает.
        private async Task HandleTokenExpiredAsync()
        {
            if (_authPromptOpen || _reloginTried) return;
            _authPromptOpen = true;
            _reloginTried = true;

            try
            {
                StopHeartbeat();
                var window = new Views.ClubSetupWindow(_clubSetup!) { Owner = this };
                if (window.ShowDialog() == true && window.Session is not null)
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

        private void MainWindow_KeyDown(object sender, System.Windows.Input.KeyEventArgs e)
        {
            try
            {
                if (e.Key == System.Windows.Input.Key.F12)
                {
                    WebView.CoreWebView2?.OpenDevToolsWindow();
                }
            }
            catch (Exception ex)
            {
                Debug.WriteLine("[DevTools] failed to open: " + ex);
            }
        }

        private async Task InitializeWebViewAsync()
        {
            await WebView.EnsureCoreWebView2Async();
            WebView.CoreWebView2.Settings.AreDefaultContextMenusEnabled = false;
            WebView.CoreWebView2.Settings.AreDevToolsEnabled = true; // выключить в production
            WebView.CoreWebView2.WebMessageReceived += CoreWebView2_WebMessageReceived;

            var exeDir = AppContext.BaseDirectory;
            var frontendFolder = Path.Combine(exeDir, "Frontend");
            var indexPath = Path.Combine(frontendFolder, "index.html");

            // Виртуальный host -> папка решает проблему с file:// origin.
            var virtualHost = "appassets.local";
            try
            {
                WebView.CoreWebView2.SetVirtualHostNameToFolderMapping(virtualHost, frontendFolder, CoreWebView2HostResourceAccessKind.Allow);
                var mappedUrl = $"https://{virtualHost}/index.html";
                Debug.WriteLine("[WebView] navigating to mapped url: " + mappedUrl + " (folder: " + frontendFolder + ")");
                WebView.CoreWebView2.Navigate(mappedUrl);
                WebView.CoreWebView2.NavigationCompleted += (_, __) =>
                {
                    try { SendMessageToWeb("{\"type\":\"pong\"}"); } catch { }
                };
                return;
            }
            catch (Exception ex)
            {
                Debug.WriteLine("[WebView] virtual host mapping failed: " + ex + ", falling back to file and dev locations");
            }

            if (File.Exists(indexPath))
            {
                WebView.CoreWebView2.Navigate(new Uri(indexPath).AbsoluteUri);
                return;
            }

            // Dev fallback: файл в корне репозитория
            var devIndex = Path.GetFullPath(Path.Combine(exeDir, "..", "..", "..", "..", "Frontend", "index.html"));
            if (File.Exists(devIndex))
            {
                WebView.CoreWebView2.Navigate(new Uri(devIndex).AbsoluteUri);
                return;
            }

            var diag = $"<html><body style=\"background:#0B0D12;color:#F3F4F6;font-family:Segoe UI;padding:30px\">" +
                       $"<h2>UI не найден</h2><p>Ожидаемые пути:</p><pre style=\"color:#9299AA\">{System.Net.WebUtility.HtmlEncode(indexPath)}\n{System.Net.WebUtility.HtmlEncode(devIndex)}</pre>" +
                       "</body></html>";
            WebView.NavigateToString(diag);
        }

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
                var sessionEndsAt = session.Remaining is { } remaining && remaining > TimeSpan.Zero
                    ? DateTimeOffset.UtcNow.Add(remaining)
                    : (DateTimeOffset?) null;
                _heartbeatService?.SetSessionEnd(sessionEndsAt);
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

        // Временный режим без проверки пользователя: сразу создаёт demo-сессию.
        private async Task StartDemoSessionAsync()
        {
            try
            {
                var session = await _apiService.GetSessionInfoAsync(Guid.NewGuid().ToString(), CancellationToken.None);
                _sessionViewModel?.StartSession(new AuthResult { Success = true, SessionId = session.SessionId, UserName = session.UserName, ExpiresAt = DateTime.UtcNow.AddHours(2) });
                _heartbeatService?.SetState(ComputerState.InSession, session.SessionId);
                SendMessageToWeb(JsonSerializer.Serialize(new { type = "sessionData", session = SessionPayload(session) }));
                SendMessageToWeb(JsonSerializer.Serialize(new { type = "authSuccess", userName = session.UserName }));
            }
            catch (Exception ex)
            {
                Debug.WriteLine("[Auth bypass] error: " + ex.Message);
                SendMessageToWeb("{\"type\":\"authFailed\",\"message\":\"internal_error\"}");
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
                    _cfgService?.Update(cfg =>
                    {
                        cfg.ProtectedApiToken = new SecureStorageService().Protect(result.Token);
                        cfg.TokenExpiresAtUtc = result.ExpiresAt ?? DateTime.UtcNow.AddHours(2);
                    });
                }

                _sessionViewModel?.StartSession(result);
                _heartbeatService?.SetState(ComputerState.Idle);
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
                var sid = _sessionViewModel?.SessionId ?? string.Empty;
                var ended = await _apiService.EndSessionAsync(sid, CancellationToken.None);
                if (!ended)
                {
                    SendMessageToWeb(JsonSerializer.Serialize(new { type = "actionFailed", message = "Не удалось завершить сессию." }));
                    return;
                }

                // Пользователь остаётся в системе: токен и клуб не трогаем.
                _sessionViewModel?.EndActiveSession();
                _heartbeatService?.SetState(ComputerState.Idle);
                SendMessageToWeb("{\"type\":\"sessionEnded\"}");
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

                _sessionViewModel?.EndSession();
                _clubSetup!.Logout();
                SendMessageToWeb("{\"type\":\"loggedOut\"}");

                // Клуб и ключ установки сохраняются: нужен только повторный вход пользователя.
                var window = new Views.ClubSetupWindow(_clubSetup, null) { Owner = this };
                if (window.ShowDialog() == true && window.Session is not null)
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
                if (type == "requestAuth")
                {
                    await StartDemoSessionAsync();
                }
                else if (type == "userLogin")
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
                    await SendSessionDataAsync(_sessionViewModel?.SessionId ?? string.Empty);
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
                        {
                            _sessionViewModel?.StartGameSession(res.SessionId, res.SessionEndsAt ?? DateTimeOffset.UtcNow.AddMinutes(1));
                            _heartbeatService?.SetState(ComputerState.InSession, res.SessionId);
                            if (res.SessionEndsAt is { } sessionEndsAt)
                                _heartbeatService?.SetSessionEnd(sessionEndsAt);
                        }

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
