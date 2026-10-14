using System.Linq;
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
        private Timer? _alive;

        internal KioskKeyboard Keyboard { get; } = new();

        /// <summary>Чёрные окна на мониторах без окна агента, пока ПК заблокирован.</summary>
        internal ScreenCover Covers { get; } = new();

        /// <summary>Сотрудник подтвердил выход PIN-кодом: агент может закрыться.</summary>
        internal bool ExitAuthorized { get; private set; }

        internal bool KioskEnabled => Keyboard.Enabled;

        internal static App Instance => (App)Current;

        protected override void OnStartup(StartupEventArgs e)
        {
            base.OnStartup(e);

            // Одна копия на сеанс пользователя (Local): чужой сеанс Windows не может помешать запуску.
            // Служба (--watchdog) запускает агент, только когда настоящий агент не работает или
            // не отвечает, поэтому занятое имя (его может занять программа клиента) не мешает ей.
            _singleInstance = new Mutex(true, @"Local\ZafiroAgent", out var isFirst);
            if (!isFirst && !e.Args.Contains("--watchdog"))
            {
                MessageBox.Show("Zafiro Agent уже запущен на этом компьютере.", "Zafiro Agent", MessageBoxButton.OK, MessageBoxImage.Information);
                Shutdown();
                return;
            }

            _config = new ConfigurationService();
            _api = new ZafiroApiClient(ZafiroApiClient.CreateHttpClient());
            var clubSetup = new ClubSetupService(_api, _config, _secure);
            AttachToService();

            Keyboard.StaffShortcut += () => Dispatcher.BeginInvoke(RequestStaffExit);
            Keyboard.Install();
            KioskPolicy.EnsureAutostart();
            RefreshKiosk();
            if (!KioskEnabled)
                KioskPolicy.EnableTaskManager(); // после сбоя агента диспетчер задач не остаётся выключенным

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

        /// <summary>
        /// Если установлена служба: она снова следит за этим сеансом, а токен ПК, сохранённый
        /// агентом до установки службы, переносится в службу и удаляется из профиля клиента.
        /// </summary>
        private void AttachToService()
        {
            var vault = DeviceTokens.Vault;
            if (vault is null) return;
            vault.Resume();
            // Сигнал «агент работает» из фонового потока: служба перезапустит зависший или приостановленный агент.
            _alive = new Timer(_ => vault.Alive(), null, TimeSpan.Zero, TimeSpan.FromSeconds(5));

            var stored = _secure.Unprotect(_config.Load().ProtectedDeviceToken);
            if (string.IsNullOrEmpty(stored) || stored == DeviceTokens.VaultMarker) return;
            SafeLog.Register(stored);
            var keep = Task.Run(() => DeviceTokens.KeepAsync(stored, CancellationToken.None)).GetAwaiter().GetResult();
            if (keep == DeviceTokens.VaultMarker)
            {
                _config.Update(cfg => cfg.ProtectedDeviceToken = _secure.Protect(keep));
                SafeLog.Write("[Vault] device token moved to the service");
            }
        }

        /// <summary>
        /// Киоск включается, когда ПК привязан к клубу (или получил токен устройства), а при
        /// установленной службе — всегда: настройки в профиле клиент может удалить. Выключает
        /// киоск только выход сотрудника по PIN.
        /// </summary>
        internal void RefreshKiosk()
        {
            if (KioskEnabled || ExitAuthorized) return;
            if (DeviceTokens.Vault is null)
            {
                var cfg = _config.Load();
                if (string.IsNullOrEmpty(cfg.ProtectedDeviceToken) && string.IsNullOrEmpty(cfg.ClubId)) return;
            }

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
            window.Loaded += (_, _) => Covers.Update(window, KioskEnabled);
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
                Covers.Update(null, false);
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
            var cfg = _config.Load();
            return _api.VerifyExitPinAsync(DeviceTokens.Resolve(_secure.Unprotect(cfg.ProtectedDeviceToken)), pin, CancellationToken.None, _secure.Unprotect(cfg.ProtectedApiToken));
        }

        protected override void OnExit(ExitEventArgs e)
        {
            Keyboard.Dispose();
            Covers.Dispose();
            _alive?.Dispose();
            _singleInstance?.Dispose();
            base.OnExit(e);
        }
    }
}
