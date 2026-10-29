<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AssetCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'asset_type' => $this->asset_type,
            'depreciation_method' => $this->depreciation_method,
            'useful_life_years' => (int) $this->useful_life_years,
            'statutory_rate_percent' => (float) $this->statutory_rate_percent,
            'salvage_value_percent' => (float) $this->salvage_value_percent,
            'gl_asset_account_code' => $this->gl_asset_account_code,
            'gl_accumulated_depreciation_code' => $this->gl_accumulated_depreciation_code,
            'gl_depreciation_expense_code' => $this->gl_depreciation_expense_code,
            'is_depreciable' => (bool) $this->is_depreciable,
            'is_active' => (bool) $this->is_active,
            'translations' => $this->translations,
        ];
    }
}
