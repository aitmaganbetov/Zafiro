<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Error envelope of the integration API (/api/v1): a stable machine-readable code
 * and a short English message. Internal details never reach the client.
 */
class ApiError extends HttpException
{
    private const MESSAGES = [
        'invalid_api_key' => 'The API key is missing or invalid.',
        'api_key_revoked' => 'The API key has been revoked.',
        'api_key_expired' => 'The API key has expired.',
        'api_key_owner_lost_access' => 'The user who created this API key no longer manages integrations of the club.',
        'subscription_inactive' => 'The club subscription is not active.',
        'ip_not_allowed' => 'Requests from this IP address are not allowed for this API key.',
        'too_many_failed_attempts' => 'Too many failed authentication attempts. Try again later.',
        'insufficient_scope' => 'The API key does not have the scope required for this operation.',
        'tenant_override_forbidden' => 'The club is determined by the API key and cannot be set in the request.',
        'payload_too_large' => 'The request body is too large.',
        'idempotency_key_required' => 'This operation requires an Idempotency-Key header.',
        'idempotency_key_invalid' => 'The Idempotency-Key header must be 1-100 characters: letters, digits, "-", "_" or ":".',
        'idempotency_key_reused' => 'This Idempotency-Key was already used with a different request.',
        'idempotency_in_progress' => 'A request with this Idempotency-Key is still being processed.',
        'rate_limited' => 'Too many requests. Slow down and retry after the delay in the Retry-After header.',
        'validation_failed' => 'The request data is invalid.',
        'not_found' => 'The resource does not exist.',
        'method_not_allowed' => 'The HTTP method is not supported for this endpoint.',
        'forbidden' => 'This operation is not allowed.',
        'bad_request' => 'The request is malformed.',
        'conflict' => 'The operation conflicts with the current state of the resource.',
        'server_error' => 'Internal server error.',
    ];

    /** @param  array<string, mixed>  $extra */
    public function __construct(
        public readonly string $errorCode,
        int $status,
        ?string $message = null,
        public readonly array $extra = [],
        array $headers = [],
    ) {
        parent::__construct($status, $message ?? self::message($errorCode), null, $headers);
    }

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? self::MESSAGES['server_error'];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    public static function response(string $code, int $status, ?string $message = null, array $extra = [], array $headers = []): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message ?? self::message($code), ...$extra]], $status, $headers);
    }

    public function render(): JsonResponse
    {
        return self::response($this->errorCode, $this->getStatusCode(), $this->getMessage(), $this->extra, $this->getHeaders());
    }

    /** Maps any exception thrown while handling an /api/v1 request to the error envelope. */
    public static function fromThrowable(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof self => $e->render(),
            $e instanceof ValidationException => self::response('validation_failed', 422, extra: ['fields' => $e->errors()]),
            $e instanceof ModelNotFoundException => self::response('not_found', 404),
            $e instanceof AuthenticationException => self::response('invalid_api_key', 401),
            $e instanceof AuthorizationException => self::response('forbidden', 403),
            $e instanceof ThrottleRequestsException => self::response('rate_limited', 429, headers: $e->getHeaders()),
            $e instanceof HttpExceptionInterface => self::response(match ($e->getStatusCode()) {
                401 => 'invalid_api_key',
                403 => 'forbidden',
                404 => 'not_found',
                405 => 'method_not_allowed',
                409 => 'conflict',
                413 => 'payload_too_large',
                429 => 'rate_limited',
                default => $e->getStatusCode() < 500 ? 'bad_request' : 'server_error',
            }, $e->getStatusCode(), headers: $e->getHeaders()),
            default => self::response('server_error', 500),
        };
    }
}
