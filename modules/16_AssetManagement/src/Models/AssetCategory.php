<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AssetCategory extends BaseEnterpriseModel
{
    protected $table = 'eam_asset_categories';

    protected function casts(): array
    {
        return [
            'useful_life_years' => 'integer',
            'useful_life_months' => 'integer',
            'statutory_rate_percent' => 'decimal:2',
            'salvage_value_percent' => 'decimal:2',
            'is_depreciable' => 'boolean',
            'is_active' => 'boolean',
            'translations' => 'array',
            'metadata' => 'array',
        ];
    }

    public function parentCategory(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function childCategories(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class, 'category_id');
    }
}
