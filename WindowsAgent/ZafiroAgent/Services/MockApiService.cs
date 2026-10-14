using System;
using System.Threading;
using System.Threading.Tasks;
using ZafiroAgent.Models;

namespace ZafiroAgent.Services
{
    /// <summary>
    /// Простая mock-реализация ApiService для разработки и demo-режима.
    /// Не хранит и не передаёт реальные секреты.
    /// </summary>
    public class MockApiService : IApiService
    {
        public Task<bool> CheckServerReachableAsync(CancellationToken cancellationToken)
        {
            return Task.FromResult(true);
        }

        public Task<AuthResult> AuthenticateAsync(string login, string password, CancellationToken cancellationToken)
        {
            // Имитируем сетевую задержку
            return Task.Run(async () =>
            {
                await Task.Delay(700, cancellationToken);

                if (cancellationToken.IsCancellationRequested)
                    return new AuthResult { Success = false, Message = "Отменено" };

                if (string.Equals(login, "demo", StringComparison.OrdinalIgnoreCase) && password == "demo")
                {
                    return new AuthResult
                    {
                        Success = true,
                        UserName = "Демонстрационный пользователь",
                        SessionId = Guid.NewGuid().ToString(),
                        ExpiresAt = DateTime.UtcNow.AddHours(2)
                    };
                }

                return new AuthResult { Success = false, Message = "Неверный логин или пароль" };
            }, cancellationToken);
        }

        public Task<SessionInfo> GetSessionInfoAsync(string sessionId, CancellationToken cancellationToken)
        {
            return Task.FromResult(new SessionInfo
            {
                SessionId = sessionId,
                UserName = "Демонстрационный пользователь",
                Balance = 150.50m,
                BonusBalance = 20.00m,
                TariffName = "Игровой 60 минут",
                StartedAt = DateTime.UtcNow.AddMinutes(-10),
                Remaining = TimeSpan.FromMinutes(50)
            });
        }

        public Task<Tariff[]> GetTariffsAsync(CancellationToken cancellationToken)
        {
            var tariffs = new[]
            {
                new Tariff { Id = "t1", Name = "Игровой 60 минут", Description = "60 минут игры", Price = 150m, Minutes = 60 },
                new Tariff { Id = "t2", Name = "Игровой 120 минут", Description = "120 минут игры", Price = 280m, Minutes = 120 },
                new Tariff { Id = "t3", Name = "Ночной тариф 180 минут", Description = "180 минут ночью", Price = 350m, Minutes = 180 }
            };
            return Task.FromResult(tariffs);
        }

        public Task<Product[]> GetProductsAsync(CancellationToken cancellationToken)
        {
            return Task.FromResult(Array.Empty<Product>());
        }

        public Task<bool> EndSessionAsync(string sessionId, CancellationToken cancellationToken)
        {
            return Task.FromResult(true);
        }

        public Task<PurchaseResult> PurchaseTariffAsync(string sessionId, string tariffId, CancellationToken cancellationToken)
        {
            // Простая логика: успешная покупка, баланс уменьшается
            return Task.FromResult(new PurchaseResult
            {
                Success = true,
                Message = "Покупка выполнена",
                NewBalance = 50.50m,
                NewBonus = 20.00m
            });
        }
    }
}
