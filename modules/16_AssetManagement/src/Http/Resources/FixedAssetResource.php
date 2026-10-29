<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FixedAssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'asset_number' => $this->asset_number,
            'barcode_tag' => $this->barcode_tag,
            'serial_number' => $this->serial_number,
            'name' => $this->name,
            'description' => $this->description,
            'category_id' => $this->category_id,
            'asset_class_id' => $this->asset_class_id,
            'location_id' => $this->location_id,
            'parent_asset_id' => $this->parent_asset_id,
            'custodian_user_id' => $this->custodian_user_id,
            'project_id' => $this->project_id,
            'status' => $this->status,
            'condition' => $this->condition,
            'acquisition_type' => $this->acquisition_type,
            'acquisition_date' => $this->acquisition_date?->format('Y-m-d'),
            'capitalization_date' => $this->capitalization_date?->format('Y-m-d'),
            'in_service_date' => $this->in_service_date?->format('Y-m-d'),
            'currency' => $this->currency,
            'acquisition_cost' => (float) $this->acquisition_cost,
            'capitalized_cost' => (float) $this->capitalized_cost,
            'salvage_value' => (float) $this->salvage_value,
            'depreciable_base' => (float) $this->depreciable_base,
            'accumulated_depreciation' => (float) $this->accumulated_depreciation,
            'impairment_loss_total' => (float) $this->impairment_loss_total,
            'revaluation_surplus_total' => (float) $this->revaluation_surplus_total,
            'carrying_amount' => (float) $this->carrying_amount,
            'depreciation_method' => $this->depreciation_method,
            'useful_life_years' => (int) $this->useful_life_years,
            'useful_life_months' => (int) $this->useful_life_months,
            'is_depreciable' => (bool) $this->is_depreciable,
            'is_fully_depreciated' => (bool) $this->is_fully_depreciated,
            'approval_status' => $this->approval_status,
            'is_locked' => (bool) $this->is_locked,
            'specifications' => $this->specifications,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
