<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Contracts\Security\HostResolver;

/**
 * The system resolver: A and AAAA records, falling back to gethostbynamel() where DNS queries are not available.
 */
final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        foreach (is_array($records) ? $records : [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = $address;
            }
        }

        if ($addresses === []) {
            $addresses = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($addresses));
    }
}
