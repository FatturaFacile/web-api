<?php

declare(strict_types=1);

namespace Modules\CRM\Contracts;

/**
 * What CPQ needs from the product configurator (module 46): a saved configuration that is ready to be quoted. The CRM
 * depends on this contract only; ConfiguratorProductConfigurationLookup is the implementation.
 */
interface ProductConfigurationLookup
{
    /**
     * A configuration of the tenant that is validated, approved or converted, or null when it does not exist or is not
     * ready (draft, pending approval, rejected).
     *
     * @return array{id: string, number: string, name: string|null, quantity: string, total_net: string, currency: string}|null
     */
    public function findQuotable(string $tenantId, string $configurationId): ?array;
}
