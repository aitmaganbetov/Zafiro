using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using ZafiroAgent.Kiosk;
using ZafiroAgent.Models;
using ZafiroAgent.Security;
using ZafiroAgent.Services;
using ZafiroAgent.Views;

namespace ZafiroAgent
{
    /// <summary>
    /// Запуск: сначала проверяется вход в клуб, и только потом открывается главное окно.
    /// Так на экране всегда одно окно: либо вход, либо агент.
    /// На ПК, настроенном сотрудником (есть токен устройства), работает режим киоска:
    /// агент нельзя закрыть без PIN-кода сотрудника (Ctrl+Alt+P).
    /// </summary>
    public partial class App : System.Windows.Application
    {
        // Одна копия агента на компьютер: вторая дублировала бы heartbeat и сессии.
        private static Mutex? _singleInstance;

        private ConfigurationService _config = null!;
        private ZafiroApiClient _api = null!;
        private readonly SecureStorageService _secure = new();
        private bool _pinOpen;

        internal KioskKeyboard Keyboard { get; } = new();

        /// <summary>Сотрудник подтвердил выход PIN-кодом: агент может закрыться.</summary>
        internal bool ExitAuthorized { get; private set; }

        internal bool KioskEnabled => Keyboard.Enabled;

        internal static App Instance => (App)Current;

        protected override void OnStartup(StartupEventArgs e)
        {
            base.OnStartup(e);

            _singleInstance = new Mutex(true, @"Global\ZafiroAgent", out var isFirst);
            if (!isFirst)
            {
                MessageBox.Show("Zafiro Agent уже запущен на этом компьютере.", "Zafiro Agent", MessageBoxButton.OK, MessageBoxImage.Information);
                Shutdown();
                return;
            }

            _config = new ConfigurationService();
            _api = new ZafiroApiClient(ZafiroApiClient.CreateHttpClient());
            var clubSetup = new ClubSetupService(_api, _config, _secure);

            Keyboard.StaffShortcut += () => Dispatcher.BeginInvoke(RequestStaffExit);
            Keyboard.Install();
            KioskPolicy.EnsureAutostart();
            RefreshKiosk();

            // Без подтверждённого клуба агент не запускается. В режиме киоска окно входа
            // закрыть нельзя: выйти можно только по PIN-коду сотрудника.
            while (!SignIn(clubSetup))
            {
                if (ExitAuthorized || !KioskEnabled)
                {
                    Shutdown();
                    return;
                }
            }

            var window = new MainWindow(_config, clubSetup, _api);
            MainWindow = window;
            ShutdownMode = ShutdownMode.OnMainWindowClose;
            window.Show();
        }

        /// <summary>Киоск включается, когда ПК настроен сотрудником (получен токен устройства).</summary>
        internal void RefreshKiosk()
        {
            if (KioskEnabled || string.IsNullOrEmpty(_config.Load().ProtectedDeviceToken)) return;
            Keyboard.Enabled = true;
            KioskPolicy.DisableTaskManager();
            SafeLog.Write("[Kiosk] enabled");
        }

        private bool SignIn(ClubSetupService clubSetup)
        {
            var stored = clubSetup.LoadSession();
            if (stored.Success) return true;

            var notice = stored.Failure is ApiFailure.NotConfigured or ApiFailure.TokenExpired ? null : stored.Message;
            var window = new ClubSetupWindow(clubSetup, notice) { Topmost = KioskEnabled };
            return window.ShowDialog() == true && window.Session is not null;
        }

        /// <summary>Ctrl+Alt+P или «Выход» в трее: агент закрывается только после проверки PIN на сервере.</summary>
        internal void RequestStaffExit()
        {
            if (_pinOpen || ExitAuthorized) return;
            _pinOpen = true;

            try
            {
                var window = new StaffPinWindow(VerifyPinAsync);
                if (window.ShowDialog() != true) return;

                ExitAuthorized = true;
                Keyboard.Enabled = false;
                KioskPolicy.EnableTaskManager();
                SafeLog.Write("[Kiosk] staff exit confirmed");

                if (MainWindow is MainWindow main)
                {
                    main.Close();
                }
                else
                {
                    foreach (Window open in Windows) open.Close();
                    Shutdown();
                }
            }
            finally
            {
                _pinOpen = false;
            }
        }

        private Task<ApiResult<bool>> VerifyPinAsync(string pin)
        {
            var deviceToken = _secure.Unprotect(_config.Load().ProtectedDeviceToken);
            return _api.VerifyExitPinAsync(deviceToken, pin, CancellationToken.None);
        }

        protected override void OnExit(ExitEventArgs e)
        {
            Keyboard.Dispose();
            _singleInstance?.Dispose();
            base.OnExit(e);
        }
    }
}
