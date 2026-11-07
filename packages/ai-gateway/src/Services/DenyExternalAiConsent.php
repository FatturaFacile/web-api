<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\Services;

use Enterprise\AiGateway\Contracts\TenantAiConsentInterface;

/**
 * Default until a module stores the choice of the tenant: no external provider.
 */
final class DenyExternalAiConsent implements TenantAiConsentInterface
{
    public function allowsExternalProviders(string $tenantId): bool
    {
        return false;
    }
}
