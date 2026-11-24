<?php

declare(strict_types=1);

namespace App\Contracts\Security;

/**
 * Resolves a host name to its IP addresses, so outbound requests can be checked against the addresses they really reach.
 */
interface HostResolver
{
    /**
     * Every IPv4 and IPv6 address of the host; an empty list when the name does not resolve.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
