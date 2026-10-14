using System.Threading;
using System.Windows;
using ZafiroAgent.Models;
using ZafiroAgent.Security;
using ZafiroAgent.Services;
using ZafiroAgent.Views;

namespace ZafiroAgent
{
    /// <summary>
    /// Запуск: сначала проверяется вход в клуб, и только потом открывается главное окно.
    /// Так на экране всегда одно окно: либо вход, либо агент.
    /// </summary>
    public partial class App : System.Windows.Application
    {
        // Одна копия агента на компьютер: вторая дублировала бы heartbeat и сессии.
        private static Mutex? _singleInstance;

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

            var config = new ConfigurationService();
            var api = new ZafiroApiClient(ZafiroApiClient.CreateHttpClient());
            var clubSetup = new ClubSetupService(api, config, new SecureStorageService());

            // Без подтверждённого клуба агент не запускается.
            if (!SignIn(clubSetup))
            {
                Shutdown();
                return;
            }

            var window = new MainWindow(config, clubSetup, api);
            MainWindow = window;
            ShutdownMode = ShutdownMode.OnMainWindowClose;
            window.Show();
        }

        protected override void OnExit(ExitEventArgs e)
        {
            _singleInstance?.Dispose();
            base.OnExit(e);
        }

        private static bool SignIn(ClubSetupService clubSetup)
        {
            var stored = clubSetup.LoadSession();
            if (stored.Success) return true;

            var notice = stored.Failure is ApiFailure.NotConfigured or ApiFailure.TokenExpired ? null : stored.Message;
            var window = new ClubSetupWindow(clubSetup, notice);
            return window.ShowDialog() == true && window.Session is not null;
        }
    }
}
