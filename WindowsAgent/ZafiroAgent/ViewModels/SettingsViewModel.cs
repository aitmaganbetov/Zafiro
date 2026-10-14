using System;
using System.Threading.Tasks;
using ZafiroAgent.Security;

namespace ZafiroAgent.ViewModels
{
    public class SettingsViewModel : BaseViewModel
    {
        private readonly SecureStorageService _secureStorage;

        public SettingsViewModel(SecureStorageService secureStorage)
        {
            _secureStorage = secureStorage;
        }

        private string _apiUrl = string.Empty;
        public string ApiUrl { get => _apiUrl; set { _apiUrl = value; RaisePropertyChanged(); } }

        private string _agentKey = string.Empty;
        public string AgentKey { get => _agentKey; set { _agentKey = value; RaisePropertyChanged(); } }

        public void LoadFromProtected(string protectedKey)
        {
            AgentKey = _secureStorage.Unprotect(protectedKey);
        }

        public string ProtectKey(string plain)
        {
            return _secureStorage.Protect(plain);
        }
    }
}
