<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Mod 155 Marketing Automation — StepLog.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $enrollment_id
 * @property string $campaign_id
 * @property string $step_key
 * @property string $type
 * @property string $outcome
 * @property string|null $detail
 * @property \Illuminate\Support\Carbon|null $executed_at
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class StepLog extends BaseEnterpriseModel
{
    protected $table = 'ma_step_logs';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'executed_at' => 'datetime',
        ];
    }
}
