<?php

declare(strict_types=1);

namespace Modules\Documents\Services;

use Enterprise\AiGateway\Contracts\TenantAiConsentInterface;
use Modules\Documents\Models\TenantAiSetting;

/**
 * The stored choice of a tenant. No row means no consent.
 */
final class TenantAiConsent implements TenantAiConsentInterface
{
    public function allowsExternalProviders(string $tenantId): bool
    {
        return TenantAiSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('external_providers_enabled', true)
            ->exists();
    }
}
