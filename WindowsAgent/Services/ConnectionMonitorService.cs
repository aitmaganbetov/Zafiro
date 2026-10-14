using System.Threading;
using System.Threading.Tasks;

namespace ZafiroAgent.Services
{
    public class ConnectionMonitorService
    {
        private readonly IApiService _apiService;

        public ConnectionMonitorService(IApiService apiService)
        {
            _apiService = apiService;
        }

        public async Task<bool> IsServerReachableAsync(CancellationToken cancellationToken)
        {
            return await _apiService.CheckServerReachableAsync(cancellationToken);
        }
    }
}
