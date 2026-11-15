<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Modules\Configurator\Models\ProductConfiguration;
use Modules\CRM\Contracts\ProductConfigurationLookup;

/**
 * Reads the saved configurations of the product configurator (module 46) for CPQ.
 */
final class ConfiguratorProductConfigurationLookup implements ProductConfigurationLookup
{
    public function findQuotable(string $tenantId, string $configurationId): ?array
    {
        $configuration = ProductConfiguration::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [ProductConfiguration::Validated, ProductConfiguration::Approved, ProductConfiguration::Converted])
            ->find($configurationId);

        if ($configuration === null) {
            return null;
        }

        return [
            'id' => (string) $configuration->id,
            'number' => (string) $configuration->number,
            'name' => $configuration->name,
            'quantity' => (string) $configuration->quantity,
            'total_net' => (string) $configuration->total_net,
            'currency' => (string) $configuration->currency,
        ];
    }
}
