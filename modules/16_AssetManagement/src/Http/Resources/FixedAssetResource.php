<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AssetManagement\Models\FixedAsset;

/**
 * A fixed asset with its lifecycle status, values (decimal strings) and coverage data. Amounts are decimal strings.
 *
 * @mixin FixedAsset
 */
final class FixedAssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
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
            'cost_center_id' => $this->cost_center_id,
            'supplier_id' => $this->supplier_id,
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_invoice_id' => $this->purchase_invoice_id,
            'purchase_invoice_line_id' => $this->purchase_invoice_line_id,
            'purchase_order_item_id' => $this->purchase_order_item_id,
            'status' => $this->status,
            'condition' => $this->condition,
            'acquisition_type' => $this->acquisition_type,
            'acquisition_date' => $this->acquisition_date?->format('Y-m-d'),
            'capitalization_date' => $this->capitalization_date?->format('Y-m-d'),
            'commissioning_date' => $this->commissioning_date?->format('Y-m-d'),
            'in_service_date' => $this->in_service_date?->format('Y-m-d'),
            'depreciation_start_date' => $this->depreciation_start_date?->format('Y-m-d'),
            'retirement_date' => $this->retirement_date?->format('Y-m-d'),
            'disposal_date' => $this->disposal_date?->format('Y-m-d'),
            'currency' => $this->currency,
            'acquisition_cost' => $this->acquisition_cost !== null ? (string) $this->acquisition_cost : null,
            'capitalized_cost' => $this->capitalized_cost !== null ? (string) $this->capitalized_cost : null,
            'salvage_value' => $this->salvage_value !== null ? (string) $this->salvage_value : null,
            'depreciable_base' => $this->depreciable_base !== null ? (string) $this->depreciable_base : null,
            'accumulated_depreciation' => $this->accumulated_depreciation !== null ? (string) $this->accumulated_depreciation : null,
            'impairment_loss_total' => $this->impairment_loss_total !== null ? (string) $this->impairment_loss_total : null,
            'revaluation_surplus_total' => $this->revaluation_surplus_total !== null ? (string) $this->revaluation_surplus_total : null,
            'carrying_amount' => $this->carrying_amount !== null ? (string) $this->carrying_amount : null,
            'replacement_cost' => $this->replacement_cost !== null ? (string) $this->replacement_cost : null,
            'depreciation_method' => $this->depreciation_method,
            'useful_life_years' => $this->useful_life_years !== null ? (int) $this->useful_life_years : null,
            'useful_life_months' => $this->useful_life_months !== null ? (int) $this->useful_life_months : null,
            'depreciation_rate_percent' => $this->depreciation_rate_percent !== null ? (string) $this->depreciation_rate_percent : null,
            'declining_balance_factor' => $this->declining_balance_factor !== null ? (string) $this->declining_balance_factor : null,
            'pro_rata_convention' => $this->pro_rata_convention,
            'total_expected_units' => $this->total_expected_units !== null ? (string) $this->total_expected_units : null,
            'consumed_units' => $this->consumed_units !== null ? (string) $this->consumed_units : null,
            'last_depreciation_date' => $this->last_depreciation_date?->format('Y-m-d'),
            'is_depreciable' => (bool) $this->is_depreciable,
            'is_fully_depreciated' => (bool) $this->is_fully_depreciated,
            'approval_status' => $this->approval_status,
            'is_locked' => (bool) $this->is_locked,
            'warranty_expiry_date' => $this->warranty_expiry_date?->format('Y-m-d'),
            'warranty_provider' => $this->warranty_provider,
            'insurance_provider' => $this->insurance_provider,
            'insurance_policy_number' => $this->insurance_policy_number,
            'insured_value' => $this->insured_value !== null ? (string) $this->insured_value : null,
            'insurance_expiry_date' => $this->insurance_expiry_date?->format('Y-m-d'),
            'specifications' => $this->specifications,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
