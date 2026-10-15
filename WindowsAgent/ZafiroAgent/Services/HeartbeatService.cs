using System;
using System.Globalization;
using System.Threading;
using System.Threading.Tasks;
using ZafiroAgent.Models;
using ZafiroAgent.Security;
using ZafiroAgent.Localization;

namespace ZafiroAgent.Services
{
    public sealed record HeartbeatOptions
    {
        public TimeSpan MinInterval { get; init; } = TimeSpan.FromSeconds(5);

        // Защита от заведомо некорректных значений сервера; обычный интервал приходит в recommended_interval_seconds.
        public TimeSpan MaxInterval { get; init; } = TimeSpan.FromHours(1);

        public TimeSpan DefaultInterval { get; init; } = TimeSpan.FromSeconds(30);

        public TimeSpan RetryDelay { get; init; } = TimeSpan.FromSeconds(10);

        public TimeSpan MaxRetryDelay { get; init; } = TimeSpan.FromMinutes(2);

        // Ошибка, которую исправляет человек (подписка, права, ПК не найден): повтор не чаще этого.
        public TimeSpan ClientErrorDelay { get; init; } = TimeSpan.FromMinutes(2);
    }

    public class HeartbeatService : IDisposable
    {
        private readonly ConfigurationService _cfgService;
        private readonly ClubSetupService _clubSetup;
        private readonly ZafiroApiClient _api;
        private readonly string _deviceName;
        private readonly Func<string?> _deviceIdProvider;
        private readonly string _agentVersion;
        private readonly HardwareCollector _hardware;
        private readonly HeartbeatOptions _options;
        private readonly Func<DateTime> _utcNow;
        private readonly SecureStorageService _secure;
        private string? _deviceToken;

        private readonly SemaphoreSlim _wake = new(0, int.MaxValue);
        private readonly object _stateLock = new();
        private ComputerState _state = ComputerState.Idle;
        private string? _sessionId;
        private DateTimeOffset? _sessionEndsAtUtc;
        private ComputerState? _lastSuccessfulState;
        private string? _lastSuccessfulSessionId;

        private volatile ClubSession? _session;
        private long _computerId;
        private CancellationTokenSource? _cts;
        private Task? _loopTask;

        public event Action<HeartbeatResponse>? OnHeartbeat;
        public event Action<Exception>? OnError;


        public HeartbeatService(
            ConfigurationService cfgService,
            ClubSetupService clubSetup,
            ZafiroApiClient api,
            string? deviceName = null,
            Func<string?>? deviceIdProvider = null,
            string? agentVersion = null,
            HeartbeatOptions? options = null,
            Func<DateTime>? utcNow = null,
            HardwareCollector? hardware = null,
            SecureStorageService? secure = null)
        {
            _secure = secure ?? new SecureStorageService();
            _cfgService = cfgService;
            _clubSetup = clubSetup;
            _api = api;
            _deviceName = deviceName ?? DeviceInfo.Name;
            // UUID и характеристики читаются лениво в фоновом потоке, чтобы не задерживать UI.
            _deviceIdProvider = deviceIdProvider ?? (() => DeviceInfo.Id);
            _agentVersion = agentVersion ?? DeviceInfo.AgentVersion;
            _hardware = hardware ?? new HardwareCollector(new WindowsHardwareProbe(), utcNow);
            _options = options ?? new HeartbeatOptions();
            _utcNow = utcNow ?? (() => DateTime.UtcNow);
        }

        public static TimeSpan ClampInterval(int recommendedSeconds, HeartbeatOptions options)
        {
            var wanted = recommendedSeconds > 0 ? TimeSpan.FromSeconds(recommendedSeconds) : options.DefaultInterval;
            if (wanted < options.MinInterval) return options.MinInterval;
            return wanted > options.MaxInterval ? options.MaxInterval : wanted;
        }

        // Экспоненциальная задержка с верхней границей: RetryDelay, 2x, 4x ... до MaxRetryDelay.
        public static TimeSpan BackoffDelay(int failures, HeartbeatOptions options)
        {
            if (failures <= 1) return options.RetryDelay;

            var factor = Math.Pow(2, Math.Min(failures - 1, 20));
            var ticks = options.RetryDelay.Ticks * factor;
            return ticks >= options.MaxRetryDelay.Ticks ? options.MaxRetryDelay : TimeSpan.FromTicks((long)ticks);
        }

        // Смена состояния будит цикл: статус уходит на backend сразу, а не через интервал.
        public void SetState(ComputerState state, string? sessionId = null)
        {
            lock (_stateLock)
            {
                var id = state == ComputerState.InSession ? sessionId : null;
                if (_state == state && _sessionId == id) return;
                if (state != ComputerState.InSession || _sessionId != id) _sessionEndsAtUtc = null;
                _state = state;
                _sessionId = id;
                _lastSuccessfulState = null;
                _lastSuccessfulSessionId = null;
            }

            _wake.Release();
        }

        public async Task<bool> WaitForStateHeartbeatAsync(ComputerState state, string? sessionId, TimeSpan timeout, CancellationToken cancellationToken)
        {
            using var timeoutSource = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
            timeoutSource.CancelAfter(timeout);

            try
            {
                while (true)
                {
                    lock (_stateLock)
                    {
                        if (Interlocked.Read(ref _computerId) > 0
                            && _lastSuccessfulState == state
                            && _lastSuccessfulSessionId == sessionId)
                        {
                            return true;
                        }
                    }

                    await Task.Delay(TimeSpan.FromMilliseconds(100), timeoutSource.Token).ConfigureAwait(false);
                }
            }
            catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
            {
                return false;
            }
        }

        public void SetSessionEnd(DateTimeOffset? sessionEndsAtUtc)
        {
            lock (_stateLock)
            {
                if (_state != ComputerState.InSession || _sessionEndsAtUtc == sessionEndsAtUtc) return;
                _sessionEndsAtUtc = sessionEndsAtUtc?.ToUniversalTime();
            }

            _wake.Release();
        }

        public void Start()
        {
            if (_loopTask is { IsCompleted: false }) return;
            _cts = new CancellationTokenSource();
            _loopTask = Task.Run(() => LoopAsync(_cts.Token));
        }

        public void Stop()
        {
            try
            {
                _cts?.Cancel();
            }
            catch { }
        }

        /// <summary>Останавливает цикл и по возможности отправляет последний статус (например, locked или shutting_down).</summary>
        public async Task StopAsync(ComputerState? finalState, TimeSpan timeout)
        {
            Stop();

            var loop = _loopTask;
            if (loop is not null)
                await Task.WhenAny(loop, Task.Delay(timeout)).ConfigureAwait(false);

            var session = _session;
            var computerId = Interlocked.Read(ref _computerId);
            if (finalState is null || session is null || computerId <= 0 || session.ExpiresAtUtc <= _utcNow()) return;

            using var cts = new CancellationTokenSource(timeout);
            try
            {
                // Сбор характеристик может обращаться к WMI, поэтому выполняется вне вызывающего потока.
                var request = await Task.Run(() => BuildRequest(finalState.Value, null, null), cts.Token).ConfigureAwait(false);
                await _api.SendHeartbeatAsync(session, computerId, request, cts.Token, _deviceToken).ConfigureAwait(false);
            }
            catch
            {
                // Последний статус отправляется по возможности.
            }
        }

        private HeartbeatRequest BuildRequest(ComputerState state, string? sessionId, DateTimeOffset? sessionEndsAtUtc) =>
            new(state, sessionId, _agentVersion, _deviceName, _hardware.GetSnapshot(), sessionEndsAtUtc);

        private HeartbeatRequest CurrentRequest()
        {
            ComputerState state;
            string? sessionId;
            DateTimeOffset? sessionEndsAtUtc;
            lock (_stateLock)
            {
                state = _state;
                sessionId = _sessionId;
                sessionEndsAtUtc = _sessionEndsAtUtc;
            }

            return BuildRequest(state, sessionId, sessionEndsAtUtc);
        }

        private async Task LoopAsync(CancellationToken cancellationToken)
        {
            var loaded = _clubSetup.LoadSession();
            if (!loaded.Success)
            {
                Report(new ZafiroApiException(loaded.Failure, loaded.Message));
                return;
            }

            var session = loaded.Value!;
            _session = session;
            SafeLog.Register(session.Token);
            LoadDeviceToken();

            long computerId = 0;
            var failures = 0;

            while (!cancellationToken.IsCancellationRequested)
            {
                var resolving = false; // ошибка случилась при определении ПК, а не при отправке heartbeat
                try
                {
                    if (session.ExpiresAtUtc <= _utcNow())
                    {
                        Report(new ZafiroApiException(ApiFailure.TokenExpired, L.T("Срок действия входа истёк. Выполните вход повторно.")));
                        return;
                    }

                    if (computerId <= 0)
                    {
                        resolving = true;
                        computerId = await ResolveComputerAsync(session, cancellationToken).ConfigureAwait(false);
                        resolving = false;
                        Interlocked.Exchange(ref _computerId, computerId);
                    }

                    var request = CurrentRequest();
                    HeartbeatResponse heartbeat;
                    try
                    {
                        heartbeat = await _api.SendHeartbeatAsync(session, computerId, request, cancellationToken, _deviceToken).ConfigureAwait(false);
                    }
                    catch (ZafiroApiException ex) when (_deviceToken is not null && ex.Failure is ApiFailure.DeviceTokenInvalid or ApiFailure.EndpointNotFound)
                    {
                        // Токен устройства отозван (или сервер старый): дальше — токен пользователя.
                        if (ex.Failure == ApiFailure.DeviceTokenInvalid)
                            _cfgService.Update(cfg => cfg.ProtectedDeviceToken = string.Empty);
                        _deviceToken = null;
                        continue;
                    }
                    lock (_stateLock)
                    {
                        if (_state == request.State && _sessionId == request.SessionId)
                        {
                            _lastSuccessfulState = request.State;
                            _lastSuccessfulSessionId = request.SessionId;
                        }
                    }
                    failures = 0;
                    OnHeartbeat?.Invoke(heartbeat);

                    await WaitAsync(ClampInterval(heartbeat.RecommendedIntervalSeconds, _options), cancellationToken).ConfigureAwait(false);
                }
                catch (OperationCanceledException) { break; }
                catch (ZafiroApiException ex) when (ex.Failure == ApiFailure.TokenExpired
                                                    || (resolving && ex.Failure is not (ApiFailure.Network or ApiFailure.InvalidResponse)))
                {
                    // 401 — нужен повторный вход (цикл запустится после него); ошибки определения
                    // ПК при запуске (не найден, нет прав) исправляет человек — их видно в агенте.
                    Report(ex);
                    return;
                }
                catch (ZafiroApiException ex) when (ex.Failure is not (ApiFailure.Network or ApiFailure.InvalidResponse))
                {
                    // 402/403/404/422: цикл не останавливается, иначе ПК «пропадёт» из панели и
                    // не узнает о новых сессиях. 422 — сессия на сервере уже другая: агент
                    // сбрасывает свою и берёт сессию из следующего ответа сервера.
                    Report(ex);
                    if (ex.Failure == ApiFailure.InvalidRequest)
                    {
                        lock (_stateLock)
                        {
                            _state = ComputerState.Idle;
                            _sessionId = null;
                            _sessionEndsAtUtc = null;
                        }
                    }

                    if (ex.Failure == ApiFailure.ComputerNotFound)
                    {
                        computerId = 0;
                        Interlocked.Exchange(ref _computerId, 0);
                    }

                    try { await WaitAsync(ex.Failure == ApiFailure.InvalidRequest ? _options.RetryDelay : _options.ClientErrorDelay, cancellationToken).ConfigureAwait(false); }
                    catch (OperationCanceledException) { break; }
                }
                catch (Exception ex)
                {
                    Report(ex);
                    failures++;

                    try { await WaitAsync(BackoffDelay(failures, _options), cancellationToken).ConfigureAwait(false); }
                    catch (OperationCanceledException) { break; }
                }
            }
        }

        private void Report(Exception ex)
        {
            SafeLog.Write("[Heartbeat] error: " + ex.Message);
            OnError?.Invoke(ex);
        }

        private async Task<long> ResolveComputerAsync(ClubSession session, CancellationToken cancellationToken)
        {
            // device_id необязателен: если UUID недоступен, поле не отправляется.
            var deviceId = _deviceIdProvider();

            var result = await _api.ResolveComputerAsync(session, _deviceName, deviceId, cancellationToken).ConfigureAwait(false);

            // ПК нет в клубе: регистрируем его. Backend идемпотентен, а при ошибках цикл ждёт backoff.
            if (!result.Success && result.Failure == ApiFailure.ComputerNotFound)
            {
                var mac = _hardware.GetSnapshot().MacAddress;
                result = await _api.RegisterComputerAsync(session, _deviceName, deviceId, mac, cancellationToken).ConfigureAwait(false);
                if (result.Success) SafeLog.Write("[Heartbeat] computer registered in club");
            }

            if (!result.Success) throw new ZafiroApiException(result.Failure, result.Message);

            var info = result.Value!;
            DeviceTokenKeep? keep = null;
            if (!string.IsNullOrEmpty(info.AgentToken))
            {
                SafeLog.Register(info.AgentToken);
                keep = await DeviceTokens.KeepAsync(info.AgentToken, cancellationToken).ConfigureAwait(false);
                _deviceToken = keep.Use;
            }

            _cfgService.Update(cfg =>
            {
                if (keep is { Persist: true })
                    cfg.ProtectedDeviceToken = _secure.Protect(keep.Use);

                cfg.ComputerId = info.ComputerId.ToString(CultureInfo.InvariantCulture);
                cfg.ComputerNumber = info.Number;
                cfg.Zone = info.ZoneName ?? string.Empty;
                cfg.DeviceName = _deviceName;
                cfg.DeviceId = deviceId ?? string.Empty;
            });
            return info.ComputerId;
        }

        private void LoadDeviceToken()
        {
            _deviceToken = DeviceTokens.Resolve(_secure.Unprotect(_cfgService.Load().ProtectedDeviceToken));
            SafeLog.Register(_deviceToken);
        }

        private async Task WaitAsync(TimeSpan delay, CancellationToken cancellationToken)
        {
            await _wake.WaitAsync(delay, cancellationToken).ConfigureAwait(false);
            while (_wake.CurrentCount > 0 && _wake.Wait(0)) { }
        }

        public void Dispose()
        {
            Stop();
        }
    }
}
