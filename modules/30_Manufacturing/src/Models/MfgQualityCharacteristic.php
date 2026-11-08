<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Manufacturing\Support\Money;

/**
 * A measured characteristic of an inspection plan with its target and tolerance limits.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $quality_plan_id
 * @property string $characteristic_name
 * @property string|null $target_value
 * @property string|null $min_tolerance
 * @property string|null $max_tolerance
 * @property string $uom
 * @property string|null $inspection_method
 * @property bool $is_critical
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
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

    /**
     * @return BelongsTo<MfgQualityPlan, $this>
     */
    public function qualityPlan(): BelongsTo
    {
        return $this->belongsTo(MfgQualityPlan::class, 'quality_plan_id');
    }

    /**
     * Whether a measured value lies within the tolerance limits (limits left empty do not apply).
     */
    public function isWithinTolerance(string|int|float $value): bool
    {
        if ($this->min_tolerance !== null && Money::compare($value, $this->min_tolerance) < 0) {
            return false;
        }

        return ! ($this->max_tolerance !== null && Money::compare($value, $this->max_tolerance) > 0);
    }
}
