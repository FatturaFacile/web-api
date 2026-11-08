<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Support\Money;
use Modules\Projects\Models\Project;

/**
 * A production order: planned, released (components reserved), in progress, completed (no more receipts expected),
 * closed (variances settled in the ledger) or cancelled. Standard unit costs are frozen at release; actual costs
 * accumulate from issues, confirmations and subcontracting, `received_value` is what output receipts took out of WIP.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $order_number
 * @property string $mfg_item_id
 * @property string $bom_id
 * @property string|null $routing_id
 * @property string|null $project_id
 * @property string|null $sales_order_id
 * @property string|null $planned_order_id
 * @property string|null $warehouse_id
 * @property string|null $receipt_warehouse_id
 * @property string $status
 * @property string $target_quantity
 * @property string $completed_quantity
 * @property string $scrapped_quantity
 * @property string $uom
 * @property Carbon $planned_start_date
 * @property Carbon $planned_end_date
 * @property Carbon|null $actual_start_date
 * @property Carbon|null $actual_end_date
 * @property string $standard_cost_total
 * @property string $actual_cost_total
 * @property string $variance_total
 * @property string $std_unit_material_cost
 * @property string $std_unit_labor_cost
 * @property string $std_unit_overhead_cost
 * @property string $std_unit_subcontract_cost
 * @property string $actual_material_cost
 * @property string $actual_labor_cost
 * @property string $actual_overhead_cost
 * @property string $actual_subcontract_cost
 * @property string $received_value
 * @property string $material_variance
 * @property string $labor_variance
 * @property string $overhead_variance
 * @property string $subcontract_variance
 * @property string|null $variance_journal_entry_id
 * @property int $lock_version
 * @property bool $is_locked
 * @property string|null $notes
 * @property string|null $created_by
 * @property string|null $released_by
 * @property Carbon|null $released_at
 * @property string|null $closed_by
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MfgItem|null $mfgItem
 * @property-read Collection<int, MfgProductionOrderLine> $lines
 * @property-read Collection<int, MfgProductionOrderOperation> $operations
 */
final class MfgProductionOrder extends BaseEnterpriseModel
{
    /** Statuses in which the order still moves material or time. */
    public const array OpenStatuses = ['released', 'in_progress'];

    /** Allowed status transitions. */
    private const array Transitions = [
        'planned' => ['released', 'cancelled'],
        'released' => ['in_progress', 'completed', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed' => ['closed', 'in_progress'],
        'closed' => [],
        'cancelled' => [],
    ];

    protected $table = 'mfg_production_orders';

    protected function casts(): array
    {
        return [
            'target_quantity' => 'decimal:4',
            'completed_quantity' => 'decimal:4',
            'scrapped_quantity' => 'decimal:4',
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
            'actual_start_date' => 'datetime',
            'actual_end_date' => 'datetime',
            'standard_cost_total' => 'decimal:4',
            'actual_cost_total' => 'decimal:4',
            'variance_total' => 'decimal:4',
            'std_unit_material_cost' => 'decimal:6',
            'std_unit_labor_cost' => 'decimal:6',
            'std_unit_overhead_cost' => 'decimal:6',
            'std_unit_subcontract_cost' => 'decimal:6',
            'actual_material_cost' => 'decimal:4',
            'actual_labor_cost' => 'decimal:4',
            'actual_overhead_cost' => 'decimal:4',
            'actual_subcontract_cost' => 'decimal:4',
            'received_value' => 'decimal:4',
            'material_variance' => 'decimal:4',
            'labor_variance' => 'decimal:4',
            'overhead_variance' => 'decimal:4',
            'subcontract_variance' => 'decimal:4',
            'released_at' => 'datetime',
            'closed_at' => 'datetime',
            'lock_version' => 'integer',
            'is_locked' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<MfgItem, $this>
     */
    public function mfgItem(): BelongsTo
    {
        return $this->belongsTo(MfgItem::class, 'mfg_item_id');
    }

    /**
     * @return BelongsTo<MfgBom, $this>
     */
    public function bom(): BelongsTo
    {
        return $this->belongsTo(MfgBom::class, 'bom_id');
    }

    /**
     * @return BelongsTo<MfgRouting, $this>
     */
    public function routing(): BelongsTo
    {
        return $this->belongsTo(MfgRouting::class, 'routing_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * @return HasMany<MfgProductionOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(MfgProductionOrderLine::class, 'production_order_id');
    }

    /**
     * @return HasMany<MfgProductionOrderOperation, $this>
     */
    public function operations(): HasMany
    {
        return $this->hasMany(MfgProductionOrderOperation::class, 'production_order_id')->orderBy('sequence');
    }

    /**
     * @return HasMany<MfgShopFloorExecution, $this>
     */
    public function shopFloorExecutions(): HasMany
    {
        return $this->hasMany(MfgShopFloorExecution::class, 'production_order_id');
    }

    /**
     * @return HasMany<MfgQualityInspection, $this>
     */
    public function qualityInspections(): HasMany
    {
        return $this->hasMany(MfgQualityInspection::class, 'production_order_id');
    }

    /**
     * @return HasMany<MfgQualityNonConformance, $this>
     */
    public function nonConformances(): HasMany
    {
        return $this->hasMany(MfgQualityNonConformance::class, 'production_order_id');
    }

    /**
     * @return HasMany<MfgProductionCost, $this>
     */
    public function costs(): HasMany
    {
        return $this->hasMany(MfgProductionCost::class, 'production_order_id');
    }

    /**
     * @return HasMany<MfgMaterialMovement, $this>
     */
    public function materialMovements(): HasMany
    {
        return $this->hasMany(MfgMaterialMovement::class, 'production_order_id');
    }

    /**
     * @return HasMany<MfgOperationConfirmation, $this>
     */
    public function confirmations(): HasMany
    {
        return $this->hasMany(MfgOperationConfirmation::class, 'production_order_id');
    }

    /**
     * Moves the order to a new status, refusing transitions the lifecycle does not allow.
     */
    public function transitionTo(string $newStatus): void
    {
        if (! in_array($newStatus, self::Transitions[$this->status] ?? [], true)) {
            throw new ManufacturingOperationException("Production order [{$this->order_number}] cannot go from [{$this->status}] to [{$newStatus}].", 'status');
        }

        $this->status = $newStatus;
        if ($newStatus === 'in_progress' && $this->actual_start_date === null) {
            $this->actual_start_date = now();
        }
        if ($newStatus === 'completed') {
            $this->actual_end_date = now();
        }
    }

    /**
     * Whether the order accepts issues, confirmations and receipts.
     */
    public function isOpen(): bool
    {
        return in_array($this->status, self::OpenStatuses, true);
    }

    /**
     * Whether the order is completed or closed.
     */
    public function isCompleted(): bool
    {
        return in_array($this->status, ['completed', 'closed'], true);
    }

    /**
     * Standard cost of one unit of output, frozen at release.
     */
    public function standardUnitCost(): string
    {
        return Money::add(Money::add($this->std_unit_material_cost, $this->std_unit_labor_cost), Money::add($this->std_unit_overhead_cost, $this->std_unit_subcontract_cost));
    }

    /**
     * Actual cost charged to the order so far (posted to WIP).
     */
    public function actualCost(): string
    {
        return Money::add(Money::add($this->actual_material_cost, $this->actual_labor_cost), Money::add($this->actual_overhead_cost, $this->actual_subcontract_cost));
    }

    /**
     * Balance of the order in WIP: actual cost charged minus the value of the output received.
     */
    public function wipBalance(): string
    {
        return Money::sub($this->actualCost(), $this->received_value);
    }
}
