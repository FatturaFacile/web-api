<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Machine time of a shop floor execution: run minutes and downtime minutes, the availability input of OEE.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $execution_id
 * @property string $machine_id
 * @property Carbon $start_time
 * @property Carbon|null $end_time
 * @property string $run_minutes
 * @property string $downtime_minutes
 * @property string $hourly_rate
 * @property string $machine_cost
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgMachineLog extends BaseEnterpriseModel
{
    protected $table = 'mfg_machine_logs';

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'run_minutes' => 'decimal:2',
            'downtime_minutes' => 'decimal:2',
            'hourly_rate' => 'decimal:4',
            'machine_cost' => 'decimal:4',
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
