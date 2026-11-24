<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AssetManagement\Models\AssetCategory;

/**
 * An asset category with its depreciation presets, fiscal parameters and ledger accounts. Amounts are decimal strings.
 *
 * @mixin AssetCategory
 */
final class AssetCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'code' => $this->code,
            'name' => $this->name,
            'asset_type' => $this->asset_type,
            'depreciation_method' => $this->depreciation_method,
            'useful_life_years' => $this->useful_life_years !== null ? (int) $this->useful_life_years : null,
            'statutory_rate_percent' => $this->statutory_rate_percent !== null ? (string) $this->statutory_rate_percent : null,
            'salvage_value_percent' => $this->salvage_value_percent !== null ? (string) $this->salvage_value_percent : null,
            'pro_rata_convention' => $this->pro_rata_convention,
            'declining_balance_factor' => $this->declining_balance_factor !== null ? (string) $this->declining_balance_factor : null,
            'fiscal_depreciation_method' => $this->fiscal_depreciation_method,
            'fiscal_rate_percent' => $this->fiscal_rate_percent !== null ? (string) $this->fiscal_rate_percent : null,
            'fiscal_useful_life_years' => $this->fiscal_useful_life_years !== null ? (int) $this->fiscal_useful_life_years : null,
            'fiscal_pro_rata_convention' => $this->fiscal_pro_rata_convention,
            'rate_reference' => $this->rate_reference,
            'gl_asset_account_code' => $this->gl_asset_account_code,
            'gl_accumulated_depreciation_code' => $this->gl_accumulated_depreciation_code,
            'gl_depreciation_expense_code' => $this->gl_depreciation_expense_code,
            'gl_impairment_account_code' => $this->gl_impairment_account_code,
            'gl_revaluation_reserve_code' => $this->gl_revaluation_reserve_code,
            'gl_disposal_gain_loss_code' => $this->gl_disposal_gain_loss_code,
            'gl_disposal_loss_code' => $this->gl_disposal_loss_code,
            'gl_disposal_proceeds_code' => $this->gl_disposal_proceeds_code,
            'gl_clearing_account_code' => $this->gl_clearing_account_code,
            'is_depreciable' => (bool) $this->is_depreciable,
            'is_active' => (bool) $this->is_active,
            'translations' => $this->translations,
        ];
    }
}
