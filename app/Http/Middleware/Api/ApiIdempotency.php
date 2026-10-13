<?php

namespace App\Http\Middleware\Api;

use App\Support\ApiError;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key support for write endpoints. The first request with a key runs
 * and its response (status < 500) is stored for 24 hours; a retry with the same
 * key and body gets the stored response, a different body is rejected, and a
 * concurrent duplicate gets 409. Usage: api.idempotent (optional) or
 * api.idempotent:required (the header is mandatory).
 */
class ApiIdempotency
{
    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        $header = $request->header('Idempotency-Key');

        if ($header === null || $header === '') {
            return $mode === 'required' ? ApiError::response('idempotency_key_required', 428) : $next($request);
        }

        if (preg_match('/^[A-Za-z0-9_:\-]{1,100}$/', $header) !== 1) {
            return ApiError::response('idempotency_key_invalid', 422);
        }

        $keyId = $request->attributes->get(AuthenticateApiKey::ATTRIBUTE)['id'];
        $hash = hash('sha256', $request->method().' '.$request->path()."\n".$request->getContent());
        $table = DB::table('api_idempotency_keys');
        $stored = (clone $table)->where('api_key_id', $keyId)->where('idempotency_key', $header)->first();

        if ($stored === null) {
            try {
                $id = (clone $table)->insertGetId(['api_key_id' => $keyId, 'idempotency_key' => $header, 'request_hash' => $hash, 'created_at' => now()]);
            } catch (UniqueConstraintViolationException) {
                return ApiError::response('idempotency_in_progress', 409);
            }

            return $this->run($request, $next, $id);
        }

        if (! hash_equals($stored->request_hash, $hash)) {
            return ApiError::response('idempotency_key_reused', 422);
        }

        if ($stored->status === null) {
            return ApiError::response('idempotency_in_progress', 409);
        }

        return response((string) $stored->response, (int) $stored->status, [
            'Content-Type' => 'application/json',
            'Idempotent-Replayed' => 'true',
        ]);
    }

    private function run(Request $request, Closure $next, int $id): Response
    {
        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            DB::table('api_idempotency_keys')->where('id', $id)->delete();

            throw $e;
        }

        if ($response->getStatusCode() >= 500) {
            DB::table('api_idempotency_keys')->where('id', $id)->delete(); // let the client retry
        } else {
            DB::table('api_idempotency_keys')->where('id', $id)->update(['status' => $response->getStatusCode(), 'response' => $response->getContent()]);
        }

        return $response;
    }
}
