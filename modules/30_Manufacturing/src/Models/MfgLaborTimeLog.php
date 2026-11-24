<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Labour time of an operator on a shop floor execution, costed at the work centre labour rate.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $execution_id
 * @property string $operator_id
 * @property Carbon $start_time
 * @property Carbon|null $end_time
 * @property string $duration_minutes
 * @property string $hourly_rate
 * @property string $labor_cost
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgLaborTimeLog extends BaseEnterpriseModel
{
    protected $table = 'mfg_labor_time_logs';

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'duration_minutes' => 'decimal:2',
            'hourly_rate' => 'decimal:4',
            'labor_cost' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<MfgShopFloorExecution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(MfgShopFloorExecution::class, 'execution_id');
    }
}
