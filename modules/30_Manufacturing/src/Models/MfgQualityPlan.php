<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MfgQualityPlan extends BaseEnterpriseModel
{
    protected $table = 'mfg_quality_plans';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function mfgItem(): BelongsTo
    {
        return $this->belongsTo(MfgItem::class, 'mfg_item_id');
    }

    public function characteristics(): HasMany
    {
        return $this->hasMany(MfgQualityCharacteristic::class, 'quality_plan_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(MfgQualityInspection::class, 'quality_plan_id');
    }
}
