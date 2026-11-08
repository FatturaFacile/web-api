<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A shop floor job card: an operator working an order operation from start to completion, with the downtime reported
 * meanwhile. Completing it records an operation confirmation with the elapsed time.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $execution_number
 * @property string $production_order_id
 * @property string $production_order_operation_id
 * @property string $work_center_id
 * @property string|null $operator_id
 * @property string|null $machine_id
 * @property string $status
 * @property string $good_units
 * @property string $scrap_units
 * @property string $downtime_minutes
 * @property string|null $downtime_reason
 * @property string|null $confirmation_id
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgShopFloorExecution extends BaseEnterpriseModel
{
    protected $table = 'mfg_shop_floor_executions';

    protected function casts(): array
    {
        return [
            'good_units' => 'decimal:4',
            'scrap_units' => 'decimal:4',
            'downtime_minutes' => 'decimal:2',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MfgProductionOrder, $this>
     */
    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(MfgProductionOrder::class, 'production_order_id');
    }

    /**
     * @return BelongsTo<MfgProductionOrderOperation, $this>
     */
    public function productionOrderOperation(): BelongsTo
    {
        return $this->belongsTo(MfgProductionOrderOperation::class, 'production_order_operation_id');
    }

    /**
     * @return BelongsTo<MfgWorkCenter, $this>
     */
    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(MfgWorkCenter::class, 'work_center_id');
    }

    /**
     * @return HasMany<MfgLaborTimeLog, $this>
     */
    public function laborLogs(): HasMany
    {
        return $this->hasMany(MfgLaborTimeLog::class, 'execution_id');
    }

    /**
     * @return HasMany<MfgMachineLog, $this>
     */
    public function machineLogs(): HasMany
    {
        return $this->hasMany(MfgMachineLog::class, 'execution_id');
    }
}
