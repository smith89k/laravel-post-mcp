<?php

namespace Smith89k\PostMcp\Media;

/**
 * Looks up the IP addresses of a host, so image URLs can be checked before fetching.
 */
class HostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = [];

        foreach (is_array($records) ? $records : [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        if ($ips === []) {
            $fallback = @gethostbynamel($host);
            $ips = is_array($fallback) ? $fallback : [];
        }

        return array_values(array_unique($ips));
    }
}
