<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MfgQualityCharacteristic extends BaseEnterpriseModel
{
    protected $table = 'mfg_quality_characteristics';

    protected function casts(): array
    {
        return [
            'target_value' => 'decimal:4',
            'min_tolerance' => 'decimal:4',
            'max_tolerance' => 'decimal:4',
            'is_critical' => 'boolean',
        ];
    }

    public function qualityPlan(): BelongsTo
    {
        return $this->belongsTo(MfgQualityPlan::class, 'quality_plan_id');
    }

    public function isWithinTolerance(float $value): bool
    {
        if ($this->min_tolerance !== null && $value < (float) $this->min_tolerance) {
            return false;
        }
        if ($this->max_tolerance !== null && $value > (float) $this->max_tolerance) {
            return false;
        }

        return true;
    }
}
