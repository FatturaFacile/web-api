<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * Data-driven Art. 25-novies CCII threshold rule of one qualified creditor (global reference data, updatable).
 *
 * @property string $id
 * @property string|null $tenant_id
 */
final class PublicCreditorThreshold extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'fin_public_creditor_thresholds';

    protected function casts(): array
    {
        return [
            'overdue_days' => 'integer',
            'absolute_threshold' => 'decimal:2',
            'absolute_threshold_no_employees' => 'decimal:2',
            'absolute_always_threshold' => 'decimal:2',
            'percent_threshold' => 'decimal:2',
            'warning_ratio' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
