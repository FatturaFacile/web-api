<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An inspection plan: inspection type (receipt, in_process, final), item, measured characteristics with tolerances and
 * the number of defects still accepted.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $plan_code
 * @property string $name
 * @property string $inspection_type
 * @property string|null $mfg_item_id
 * @property string|null $description
 * @property int $acceptance_defect_limit
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, MfgQualityCharacteristic> $characteristics
 */
final class MfgQualityPlan extends BaseEnterpriseModel
{
    protected $table = 'mfg_quality_plans';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'acceptance_defect_limit' => 'integer',
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
     * @return HasMany<MfgQualityCharacteristic, $this>
     */
    public function characteristics(): HasMany
    {
        return $this->hasMany(MfgQualityCharacteristic::class, 'quality_plan_id');
    }

    /**
     * @return HasMany<MfgQualityInspection, $this>
     */
    public function inspections(): HasMany
    {
        return $this->hasMany(MfgQualityInspection::class, 'quality_plan_id');
    }
}
