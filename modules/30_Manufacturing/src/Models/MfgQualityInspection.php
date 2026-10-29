<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\HumanResources\Models\Employee;

final class MfgQualityInspection extends BaseEnterpriseModel
{
    protected $table = 'mfg_quality_inspections';

    protected function casts(): array
    {
        return [
            'sample_size' => 'integer',
            'defect_count' => 'integer',
            'inspected_at' => 'datetime',
            'characteristic_results' => 'array',
        ];
    }

    public function qualityPlan(): BelongsTo
    {
        return $this->belongsTo(MfgQualityPlan::class, 'quality_plan_id');
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(MfgProductionOrder::class, 'production_order_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(MfgOperation::class, 'operation_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'inspector_id');
    }

    public function nonConformances(): HasMany
    {
        return $this->hasMany(MfgQualityNonConformance::class, 'quality_inspection_id');
    }

    public function isPassed(): bool
    {
        return $this->status === 'passed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
