using System;
using System.Threading;
using System.Threading.Tasks;
using System.Windows.Input;
using ZafiroAgent.Infrastructure;
using ZafiroAgent.Services;

namespace ZafiroAgent.ViewModels
{
    public class LoginViewModel : BaseViewModel
    {
        private readonly AuthenticationService _authService;
        private CancellationTokenSource? _cts;

        public LoginViewModel(AuthenticationService authService)
        {
            _authService = authService;
            LoginCommand = new RelayCommand(async _ => await LoginAsync(), _ => !IsBusy);
        }

        private string _login = string.Empty;
        public string Login
        {
            get => _login;
            set { _login = value; RaisePropertyChanged(); }
        }

        private string _password = string.Empty;
        public string Password
        {
            get => _password;
            set { _password = value; RaisePropertyChanged(); }
        }

        private bool _isBusy;
        public bool IsBusy
        {
            get => _isBusy;
            set { _isBusy = value; RaisePropertyChanged(); ((RelayCommand)LoginCommand).RaiseCanExecuteChanged(); }
        }

        private string? _error;
        public string? Error { get => _error; set { _error = value; RaisePropertyChanged(); } }

        public ICommand LoginCommand { get; }

        public Func<ZafiroAgent.Models.AuthResult, System.Threading.Tasks.Task>? OnAuthenticated;

        public async Task LoginAsync()
        {
            if (IsBusy) return;
            IsBusy = true;
            Error = null;
            _cts = new CancellationTokenSource();
            try
            {
                var result = await _authService.AuthenticateAsync(Login, Password, _cts.Token);
                if (result.Success)
                {
                    if (OnAuthenticated != null)
                        await OnAuthenticated.Invoke(result);
                }
                else
                {
                    Error = result.Message ?? "Ошибка авторизации";
                }
            }
            catch (OperationCanceledException)
            {
                Error = "Операция отменена";
            }
            catch (Exception ex)
            {
                Error = "Ошибка: " + ex.Message;
            }
            finally
            {
                IsBusy = false;
            }
        }
    }
}
