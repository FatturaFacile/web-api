<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A quality inspection at an inspection point (receipt of output, operation, final) against a plan: measured results,
 * defects, accepted and rejected quantities, passed or failed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $inspection_number
 * @property string $quality_plan_id
 * @property string $inspection_point
 * @property string|null $production_order_id
 * @property string|null $production_order_operation_id
 * @property string|null $material_movement_id
 * @property string|null $mfg_item_id
 * @property string|null $operation_id
 * @property string|null $inspector_id
 * @property int $sample_size
 * @property int $defect_count
 * @property string $quantity_inspected
 * @property string $quantity_accepted
 * @property string $quantity_rejected
 * @property string $status
 * @property Carbon|null $inspected_at
 * @property string|null $notes
 * @property list<array<string, mixed>>|null $characteristic_results
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgQualityInspection extends BaseEnterpriseModel
{
    protected $table = 'mfg_quality_inspections';

    protected function casts(): array
    {
        return [
            'sample_size' => 'integer',
            'defect_count' => 'integer',
            'quantity_inspected' => 'decimal:4',
            'quantity_accepted' => 'decimal:4',
            'quantity_rejected' => 'decimal:4',
            'inspected_at' => 'datetime',
            'characteristic_results' => 'array',
        ];
    }

    /**
     * @return BelongsTo<MfgQualityPlan, $this>
     */
    public function qualityPlan(): BelongsTo
    {
        return $this->belongsTo(MfgQualityPlan::class, 'quality_plan_id');
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
     * @return HasMany<MfgQualityNonConformance, $this>
     */
    public function nonConformances(): HasMany
    {
        return $this->hasMany(MfgQualityNonConformance::class, 'quality_inspection_id');
    }

    /**
     * Whether the inspection passed.
     */
    public function isPassed(): bool
    {
        return $this->status === 'passed';
    }

    /**
     * Whether the inspection failed.
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
