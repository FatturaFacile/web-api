<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\Services;

use Enterprise\AiGateway\Contracts\AiDriverInterface;
use Enterprise\AiGateway\Contracts\TenantAiConsentInterface;
use Enterprise\AiGateway\Exceptions\ExternalAiNotAllowedException;

/**
 * Picks the driver for tenant data. It calls the driver directly on purpose: the gateway fallback could hand a request
 * that failed on a local driver to an external one.
 */
final readonly class AiProviderGuard
{
    public function __construct(
        private AiDriverRegistry $registry,
        private TenantAiConsentInterface $consent,
    ) {}

    public function isExternal(string $driverName): bool
    {
        return ! in_array(strtolower($driverName), array_map(strtolower(...), (array) config('ai.local_drivers', ['local'])), true);
    }

    /**
     * @throws ExternalAiNotAllowedException
     */
    public function driverFor(string $tenantId, string $driverName): AiDriverInterface
    {
        if ($this->isExternal($driverName) && ! $this->consent->allowsExternalProviders($tenantId)) {
            throw ExternalAiNotAllowedException::forDriver($driverName);
        }

        return $this->registry->get($driverName);
    }
}
