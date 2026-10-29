<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HumanResources\Models\Employee;

final class MfgQualityNonConformance extends BaseEnterpriseModel
{
    protected $table = 'mfg_quality_non_conformances';

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function qualityInspection(): BelongsTo
    {
        return $this->belongsTo(MfgQualityInspection::class, 'quality_inspection_id');
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(MfgProductionOrder::class, 'production_order_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reported_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'resolved_by');
    }
}
