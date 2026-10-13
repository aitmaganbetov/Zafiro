<?php

namespace App\Services\Integrations;

/**
 * SSRF protection for webhook URLs: only http(s) on standard ports, HTTPS unless
 * disabled, and every resolved address must be public. The checked address is
 * returned so the request is pinned to it (no DNS rebinding between check and use).
 */
class WebhookUrlGuard
{
    /** @return array{host: string, port: int, ip: string}|string resolved target or a reason */
    public function check(string $url): array|string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return 'invalid_url';
        }

        if ($scheme !== 'https' && config('integrations.webhook_require_https')) {
            return 'https_required';
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (! in_array($port, [80, 443, 8080, 8443], true)) {
            return 'port_not_allowed';
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolve($host);

        if ($ips === []) {
            return 'host_not_resolved';
        }

        if (! config('integrations.webhook_allow_private_hosts')) {
            foreach ($ips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    return 'private_address';
                }
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /** @return list<string> */
    protected function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $r): ?string => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
    }
}
