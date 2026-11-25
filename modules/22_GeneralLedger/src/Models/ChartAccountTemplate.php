<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * Seeded multi-standard Chart of Accounts template catalog (Swiss KMU/Käfer, Italian Civil Code/OIC, etc.).
 * Global rows (tenant_id = null) are shared across tenants; tenants can also customize/clone templates.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $key
 * @property string $standard
 * @property string $country
 * @property string $currency
 * @property array<string, mixed> $texts
 * @property list<array<string, mixed>> $nodes
 * @property list<array<string, mixed>>|null $default_mappings
 * @property bool $is_active
 */
final class ChartAccountTemplate extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'gl_coa_templates';

    protected function casts(): array
    {
        return [
            'texts' => 'array',
            'nodes' => 'array',
            'default_mappings' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
