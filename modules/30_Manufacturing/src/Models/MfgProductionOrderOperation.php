<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An operation of a production order, copied from the routing at release with the planned times for the order
 * quantity and the work centre rates in force; confirmations add actual times, quantities and costs.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $production_order_id
 * @property string|null $operation_id
 * @property int $sequence
 * @property string|null $operation_code
 * @property string|null $name
 * @property string $work_center_id
 * @property string|null $machine_center_id
 * @property string $status
 * @property string $planned_setup_minutes
 * @property string $actual_setup_minutes
 * @property string $planned_run_minutes
 * @property string $actual_run_minutes
 * @property string $planned_labor_minutes
 * @property string $actual_labor_minutes
 * @property string $labor_rate
 * @property string $overhead_rate
 * @property string $actual_labor_cost
 * @property string $actual_overhead_cost
 * @property bool $is_subcontracted
 * @property string $subcontract_unit_cost
 * @property string $completed_quantity
 * @property string $scrapped_quantity
 * @property Carbon|null $scheduled_date
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MfgWorkCenter|null $workCenter
 */
final class MfgProductionOrderOperation extends BaseEnterpriseModel
{
    protected $table = 'mfg_production_order_operations';

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'planned_setup_minutes' => 'decimal:2',
            'actual_setup_minutes' => 'decimal:2',
            'planned_run_minutes' => 'decimal:2',
            'actual_run_minutes' => 'decimal:2',
            'planned_labor_minutes' => 'decimal:2',
            'actual_labor_minutes' => 'decimal:2',
            'labor_rate' => 'decimal:4',
            'overhead_rate' => 'decimal:4',
            'actual_labor_cost' => 'decimal:4',
            'actual_overhead_cost' => 'decimal:4',
            'is_subcontracted' => 'boolean',
            'subcontract_unit_cost' => 'decimal:4',
            'completed_quantity' => 'decimal:4',
            'scrapped_quantity' => 'decimal:4',
            'scheduled_date' => 'date',
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
     * @return BelongsTo<MfgOperation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(MfgOperation::class, 'operation_id');
    }

    /**
     * @return BelongsTo<MfgWorkCenter, $this>
     */
    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(MfgWorkCenter::class, 'work_center_id');
    }

    /**
     * @return BelongsTo<MfgMachineCenter, $this>
     */
    public function machineCenter(): BelongsTo
    {
        return $this->belongsTo(MfgMachineCenter::class, 'machine_center_id');
    }

    /**
     * @return HasMany<MfgShopFloorExecution, $this>
     */
    public function executions(): HasMany
    {
        return $this->hasMany(MfgShopFloorExecution::class, 'production_order_operation_id');
    }

    /**
     * @return HasMany<MfgOperationConfirmation, $this>
     */
    public function confirmations(): HasMany
    {
        return $this->hasMany(MfgOperationConfirmation::class, 'production_order_operation_id');
    }

    /**
     * Whether the operation is finished.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
