<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * A fiscal parameter of the agent engine. Global rows (tenant_id null) are the seeded statutory defaults; a tenant row
 * overrides them.
 *
 * @property string $code
 * @property string|null $mandate_type
 * @property int|null $year
 * @property string|null $value
 * @property array<int, array<string, mixed>>|null $payload
 * @property string|null $label_key
 */
final class FiscalParameter extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'agc_fiscal_parameters';

    protected function casts(): array
    {
        return [
            'value' => 'decimal:6',
            'payload' => 'array',
            'year' => 'integer',
        ];
    }
}
