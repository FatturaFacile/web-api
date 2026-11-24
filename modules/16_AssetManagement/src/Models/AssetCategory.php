<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eloquent model of the asset category records of the asset management module.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string|null $parent_id
 * @property string|null $code
 * @property string|null $name
 * @property string|null $asset_type
 * @property string|null $depreciation_method
 * @property int|null $useful_life_years
 * @property int|null $useful_life_months
 * @property string|null $statutory_rate_percent
 * @property string|null $salvage_value_percent
 * @property string|null $gl_asset_account_code
 * @property string|null $gl_accumulated_depreciation_code
 * @property string|null $gl_depreciation_expense_code
 * @property string|null $gl_impairment_account_code
 * @property string|null $gl_revaluation_reserve_code
 * @property string|null $gl_disposal_gain_loss_code
 * @property string|null $gl_clearing_account_code
 * @property bool $is_depreciable
 * @property bool $is_active
 * @property array<string, mixed>|null $translations
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property string|null $pro_rata_convention
 * @property string|null $declining_balance_factor
 * @property string|null $fiscal_depreciation_method
 * @property string|null $fiscal_rate_percent
 * @property int|null $fiscal_useful_life_years
 * @property string|null $fiscal_pro_rata_convention
 * @property string|null $rate_reference
 * @property string|null $gl_disposal_proceeds_code
 * @property string|null $gl_disposal_loss_code
 */
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
            'declining_balance_factor' => 'decimal:4',
            'fiscal_rate_percent' => 'decimal:4',
            'fiscal_useful_life_years' => 'integer',
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
