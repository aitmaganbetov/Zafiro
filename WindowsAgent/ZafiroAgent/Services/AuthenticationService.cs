using System;
using System.Threading;
using System.Threading.Tasks;
using ZafiroAgent.Models;

namespace ZafiroAgent.Services
{
    public class AuthenticationService
    {
        private readonly IApiService _apiService;
        private readonly object _lock = new object();
        private Task<AuthResult>? _currentAuthTask;

        public AuthenticationService(IApiService apiService)
        {
            _apiService = apiService;
        }

        public bool IsAuthenticating => _currentAuthTask != null && !_currentAuthTask.IsCompleted;

        public Task<AuthResult> AuthenticateAsync(string login, string password, CancellationToken cancellationToken)
        {
            lock (_lock)
            {
                if (IsAuthenticating)
                    throw new InvalidOperationException("Аутентификация уже выполняется");

                _currentAuthTask = _apiService.AuthenticateAsync(login, password, cancellationToken);
                _currentAuthTask.ContinueWith(t => { _currentAuthTask = null; }, TaskScheduler.Default);
                return _currentAuthTask;
            }
        }
    }
}
