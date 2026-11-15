<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models\Payroll;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Year-versioned Swiss social insurance rate set. A null tenant_id marks a system default visible to every
 * tenant; tenant rows override the defaults for the same validity window.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $code
 * @property string $name
 * @property int $year
 * @property \Illuminate\Support\Carbon $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_to
 * @property array<string, mixed> $rates
 * @property bool $is_active
 */
final class PayrollRateSet extends BaseEnterpriseModel
{
    protected $table = 'hr_ch_rate_sets';

    public function isSharedLookup(): bool
    {
        return true;
    }

    public function isSystemDefault(): bool
    {
        return $this->tenant_id === null;
    }

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'rates' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
