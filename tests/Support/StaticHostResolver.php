<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Security\HostResolver;

/**
 * DNS for the tests: named hosts resolve to the given addresses, every other name to one public address (or to
 * nothing when the fallback is null), so no test depends on the network.
 */
final class StaticHostResolver implements HostResolver
{
    public const string DEFAULT_ADDRESS = '93.184.215.14';

    /**
     * @param  array<string, list<string>>  $records
     */
    public function __construct(private readonly array $records = [], private readonly ?string $fallback = self::DEFAULT_ADDRESS) {}

    public function resolve(string $host): array
    {
        return $this->records[$host] ?? ($this->fallback === null ? [] : [$this->fallback]);
    }
}
