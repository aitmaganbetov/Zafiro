using System;
using System.Threading;
using System.Windows;
using ZafiroAgent.Models;
using ZafiroAgent.Services;

namespace ZafiroAgent.Views
{
    public partial class ClubSetupWindow : Window
    {
        private readonly ClubSetupService _setup;
        private readonly bool _relogin;
        private readonly CancellationTokenSource _cts = new();
        private bool _busy;

        public ClubSession? Session { get; private set; }

        public ClubSetupWindow(ClubSetupService setup, string? notice = null)
        {
            InitializeComponent();
            _setup = setup;
            _relogin = setup.IsClubConfigured;

            var computer = string.IsNullOrWhiteSpace(setup.ComputerNumber) ? setup.ComputerId : setup.ComputerNumber;
            ComputerText.Text = $"ПК: {(string.IsNullOrWhiteSpace(computer) ? "не задан" : computer)}";
            ZoneText.Text = $"Зона: {(string.IsNullOrWhiteSpace(setup.Zone) ? "не задана" : setup.Zone)}";

            if (_relogin)
            {
                HeaderText.Text = "Повторный вход";
                ClubText.Text = $"Клуб: {setup.ClubName}";
                KeyPanel.Visibility = Visibility.Collapsed;
            }
            else
            {
                ClubText.Text = "Введите ключ установки клуба и данные вашей учётной записи.";
            }

            if (!string.IsNullOrEmpty(notice)) ErrorText.Text = notice;

            SubmitButton.Click += async (_, _) => await SubmitAsync();
            Closed += (_, _) => _cts.Cancel();
        }

        private async System.Threading.Tasks.Task SubmitAsync()
        {
            if (_busy) return;
            var phone = PhoneNumberFormatter.NormalizeKazakhstanNumber(PhoneBox.Text);
            if (phone is null)
            {
                ErrorText.Text = "Введите 10 цифр номера после +7.";
                return;
            }

            _busy = true;
            SubmitButton.IsEnabled = false;
            ErrorText.Text = string.Empty;
            var allowRetry = true;

            try
            {
                var result = _relogin
                    ? await _setup.ReloginAsync(phone, PasswordBox.Password, _cts.Token)
                    : await _setup.SetupAsync(ClubKeyBox.Password, phone, PasswordBox.Password, _cts.Token);

                if (result.Success)
                {
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
            finally
            {
                _busy = false;
            }

            SubmitButton.IsEnabled = allowRetry;
        }
    }
}
