<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use Modules\Projects\Models\Project;

final class MfgProductionOrder extends BaseEnterpriseModel
{
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
            'lock_version' => 'integer',
            'is_locked' => 'boolean',
        ];
    }

    public function mfgItem(): BelongsTo
    {
        return $this->belongsTo(MfgItem::class, 'mfg_item_id');
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(MfgBom::class, 'bom_id');
    }

    public function routing(): BelongsTo
    {
        return $this->belongsTo(MfgRouting::class, 'routing_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MfgProductionOrderLine::class, 'production_order_id');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(MfgProductionOrderOperation::class, 'production_order_id')->orderBy('sequence');
    }

    public function shopFloorExecutions(): HasMany
    {
        return $this->hasMany(MfgShopFloorExecution::class, 'production_order_id');
    }

    public function qualityInspections(): HasMany
    {
        return $this->hasMany(MfgQualityInspection::class, 'production_order_id');
    }

    public function nonConformances(): HasMany
    {
        return $this->hasMany(MfgQualityNonConformance::class, 'production_order_id');
    }

    public function costs(): HasMany
    {
        return $this->hasMany(MfgProductionCost::class, 'production_order_id');
    }

    public function transitionTo(string $newStatus): void
    {
        $allowedTransitions = [
            'planned' => ['released', 'cancelled'],
            'released' => ['scheduled', 'in_progress', 'cancelled'],
            'scheduled' => ['in_progress', 'cancelled'],
            'in_progress' => ['completed', 'cancelled'],
            'completed' => ['closed'],
            'closed' => [],
            'cancelled' => [],
        ];

        if (! isset($allowedTransitions[$this->status]) || ! in_array($newStatus, $allowedTransitions[$this->status], true)) {
            throw new InvalidArgumentException("Invalid production order state transition from [{$this->status}] to [{$newStatus}].");
        }

        $this->status = $newStatus;
        if ($newStatus === 'in_progress' && $this->actual_start_date === null) {
            $this->actual_start_date = now();
        }
        if ($newStatus === 'completed' && $this->actual_end_date === null) {
            $this->actual_end_date = now();
        }
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, ['completed', 'closed'], true);
    }
}
