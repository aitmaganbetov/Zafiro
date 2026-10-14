using System.Threading;
using System.Threading.Tasks;
using ZafiroAgent.Models;

namespace ZafiroAgent.Services
{
    public interface IApiService
    {
        Task<AuthResult> AuthenticateAsync(string login, string password, CancellationToken cancellationToken);
        Task<bool> CheckServerReachableAsync(CancellationToken cancellationToken);
        Task<SessionInfo> GetSessionInfoAsync(string sessionId, CancellationToken cancellationToken);
        Task<Product[]> GetProductsAsync(CancellationToken cancellationToken);
        Task<Tariff[]> GetTariffsAsync(CancellationToken cancellationToken);
        Task<PurchaseResult> PurchaseTariffAsync(string tariffId, CancellationToken cancellationToken);
        Task<bool> EndSessionAsync(string sessionId, CancellationToken cancellationToken);
    }
}
