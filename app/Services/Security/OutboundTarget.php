<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * A host checked by the outbound guard, with the public addresses it resolved to at that moment. `allowListed` hosts
 * (local development only) were not resolved and carry no address.
 */
final readonly class OutboundTarget
{
    /**
     * @param  list<string>  $addresses
     */
    public function __construct(
        public string $host,
        public int $port,
        public array $addresses,
        public bool $allowListed = false,
    ) {}

    /**
     * The `CURLOPT_RESOLVE` entry that makes curl connect to the checked addresses only, so a second DNS answer (DNS
     * rebinding) cannot send the request elsewhere. Null when there is nothing to pin (an IP literal or an allow-listed
     * host).
     */
    public function curlResolveEntry(): ?string
    {
        if ($this->allowListed || $this->addresses === [] || filter_var($this->host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $addresses = array_map(
            static fn (string $address): string => str_contains($address, ':') ? "[{$address}]" : $address,
            $this->addresses,
        );

        return "{$this->host}:{$this->port}:".implode(',', $addresses);
    }
}
