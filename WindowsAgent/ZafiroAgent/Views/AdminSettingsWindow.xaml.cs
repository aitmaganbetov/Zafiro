using System;
using System.Windows;
using ZafiroAgent.Services;
using ZafiroAgent.Security;
using ZafiroAgent.Configuration;

namespace ZafiroAgent.Views
{
    public partial class AdminSettingsWindow : Window
    {
        private readonly ConfigurationService _cfgService = new ConfigurationService();
        private readonly SecureStorageService _secure = new SecureStorageService();

        public AdminSettingsWindow()
        {
            InitializeComponent();
            Load();
            BtnSave.Click += BtnSave_Click;
            BtnClose.Click += (_, __) => Close();
        }

        private void Load()
        {
            var cfg = _cfgService.Load();
            ApiUrlBox.Text = cfg.ApiUrl;
            ComputerIdBox.Text = cfg.ComputerId;
            ZoneBox.Text = cfg.Zone;
            if (!string.IsNullOrEmpty(cfg.ProtectedAgentKey))
            {
                AgentKeyBox.Password = _secure.Unprotect(cfg.ProtectedAgentKey);
            }
        }

        private void BtnSave_Click(object? sender, RoutedEventArgs e)
        {
            var cfg = _cfgService.Load();
            cfg.ApiUrl = ApiUrlBox.Text ?? string.Empty;
            cfg.ComputerId = ComputerIdBox.Text?.Trim() ?? string.Empty;
            cfg.Zone = ZoneBox.Text?.Trim() ?? string.Empty;
            cfg.ProtectedAgentKey = _secure.Protect(AgentKeyBox.Password ?? string.Empty);
            _cfgService.Save(cfg);
            MessageBox.Show("Сохранено", "Настройки", MessageBoxButton.OK, MessageBoxImage.Information);
            Close();
        }
    }
}
