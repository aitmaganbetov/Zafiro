using System;
using System.Threading;
using System.Threading.Tasks;
using ZafiroAgent.Models;

namespace ZafiroAgent.ViewModels
{
    public class SessionViewModel : BaseViewModel
    {
        private string _userName = string.Empty;
        private string _sessionId = string.Empty;

        public string UserName { get => _userName; set { _userName = value; RaisePropertyChanged(); } }
        public string SessionId { get => _sessionId; set { _sessionId = value; RaisePropertyChanged(); } }

        private TimeSpan _remaining = TimeSpan.Zero;
        public TimeSpan Remaining { get => _remaining; set { _remaining = value; RaisePropertyChanged(); } }

        public void StartSession(AuthResult auth)
        {
            if (auth == null || !auth.Success) return;
            UserName = auth.UserName ?? "Клиент";
            SessionId = string.Empty;
            Remaining = TimeSpan.Zero;
        }

        public void StartGameSession(string sessionId, DateTimeOffset sessionEndsAt)
        {
            SessionId = sessionId;
            Remaining = sessionEndsAt.UtcDateTime - DateTime.UtcNow;
        }

        // Завершает игровую сессию, оставляя пользователя в системе.
        public void EndActiveSession()
        {
            SessionId = string.Empty;
            Remaining = TimeSpan.Zero;
        }

        public void EndSession()
        {
            UserName = string.Empty;
            SessionId = string.Empty;
            Remaining = TimeSpan.Zero;
        }
    }
}
