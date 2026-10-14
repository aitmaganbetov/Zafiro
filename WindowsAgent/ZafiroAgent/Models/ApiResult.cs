namespace ZafiroAgent.Models
{
    public enum ApiFailure
    {
        None,
        InvalidInput,
        InvalidKey,
        KeyRevoked,
        InvalidCredentials,
        Forbidden,
        SubscriptionInactive,
        ClubMismatch,
        InvalidResponse,
        InvalidEndpoint,
        Network,
        TokenExpired,
        ClubAlreadyConfigured,
        ComputerNotFound,
        EndpointNotFound,
        InvalidRequest,
        NotConfigured,
        DeviceTokenInvalid,
        ServiceUnavailable,
        Unknown
    }

    public sealed class ApiResult<T>
    {
        public bool Success { get; private init; }
        public T? Value { get; private init; }
        public ApiFailure Failure { get; private init; }
        // Сообщение безопасно для показа: не содержит ключ, пароль и токен.
        public string Message { get; private init; } = string.Empty;

        public static ApiResult<T> Ok(T value) => new() { Success = true, Value = value };

        public static ApiResult<T> Fail(ApiFailure failure, string message) =>
            new() { Failure = failure, Message = message };
    }
}
