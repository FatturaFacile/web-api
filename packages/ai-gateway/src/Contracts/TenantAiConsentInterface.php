<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\Contracts;

interface TenantAiConsentInterface
{
    /**
     * Whether the tenant explicitly enabled sending its data to providers outside the installation.
     */
    public function allowsExternalProviders(string $tenantId): bool;
}
