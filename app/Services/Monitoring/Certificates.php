<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\Cache;

/** Expiry dates of the HTTPS certificates, checked hourly by the scheduler: the page never connects out. */
class Certificates
{
    private const CACHE_KEY = 'monitoring.certificates';

    /** @return list<string> */
    public function hosts(): array
    {
        $hosts = config('monitoring.certificate_hosts') ?: [parse_url((string) config('app.url'), PHP_URL_HOST), config('app.superadmin_domain'), config('app.wiki_domain')];

        return array_values(array_unique(array_filter($hosts, fn ($host) => is_string($host) && $host !== '' && $host !== 'localhost' && ! str_ends_with($host, '.localhost'))));
    }

    /** @return array{checked_at: int, hosts: array<string, int|null>}|null expiry as Unix time, null — not reachable */
    public function cached(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    /** @return array<string, int|null> */
    public function refresh(): array
    {
        $expiry = [];
        foreach ($this->hosts() as $host) {
            $expiry[$host] = $this->expiresAt($host);
        }
        Cache::forever(self::CACHE_KEY, ['checked_at' => time(), 'hosts' => $expiry]);

        return $expiry;
    }

    private function expiresAt(string $host): ?int
    {
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $socket = @stream_socket_client('ssl://'.$host.':443', $errorCode, $errorMessage, 5, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            return null;
        }
        $certificate = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
        fclose($socket);
        $parsed = $certificate ? openssl_x509_parse($certificate) : false;

        return is_array($parsed) && isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;
    }
}
