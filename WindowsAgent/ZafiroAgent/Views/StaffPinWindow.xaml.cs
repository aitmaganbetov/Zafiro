using System;
using System.Threading.Tasks;
using System.Windows;
using ZafiroAgent.Models;
using ZafiroAgent.Localization;

namespace ZafiroAgent.Views
{
    /// <summary>Окно PIN-кода сотрудника (Ctrl+Alt+P или «Выход» в трее). PIN проверяет сервер.</summary>
    public partial class StaffPinWindow : Window
    {
        private readonly Func<string, Task<ApiResult<bool>>> _verify;
        private bool _busy;

        public StaffPinWindow(Func<string, Task<ApiResult<bool>>> verify)
        {
            InitializeComponent();
            _verify = verify;
            Title = TitleText.Text = L.T("Выход сотрудника");
            HintText.Text = L.T("Введите PIN-код из настроек клуба, чтобы закрыть Zafiro Agent.");
            System.Windows.Automation.AutomationProperties.SetName(PinBox, L.T("PIN-код"));
            CancelButton.Content = L.T("Отмена");
            SubmitButton.Content = L.T("Закрыть агент");
            SubmitButton.Click += async (_, _) => await SubmitAsync();
            CancelButton.Click += (_, _) => DialogResult = false;
            Loaded += (_, _) =>
            {
                Activate();
                PinBox.Focus();
            };
        }

        private async Task SubmitAsync()
        {
            if (_busy) return;
            _busy = true;
            SubmitButton.IsEnabled = false;
            ErrorText.Text = L.T("Проверяем…");

            try
            {
                var result = await _verify(PinBox.Password.Trim());
                if (result.Success)
                {
                    DialogResult = true;
                    return;
                }

                ErrorText.Text = result.Message;
                PinBox.Clear();
                PinBox.Focus();
            }
            finally
            {
                _busy = false;
                SubmitButton.IsEnabled = true;
            }
        }
    }
}
