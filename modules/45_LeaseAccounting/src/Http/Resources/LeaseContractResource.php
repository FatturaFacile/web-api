<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\LeaseAccounting\Models\LeaseContract;

/**
 * A lease contract with its terms, status and, when loaded, the schedule versions.
 *
 * @mixin LeaseContract
 */
final class LeaseContractResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'title' => $this->title,
            'lessor_name' => $this->lessor_name,
            'lessor_contact_id' => $this->lessor_contact_id,
            'asset_class' => $this->asset_class,
            'asset_description' => $this->asset_description,
            'fixed_asset_id' => $this->fixed_asset_id,
            'accounting_standard' => $this->accounting_standard,
            'currency' => $this->currency,
            'commencement_date' => $this->commencement_date->toDateString(),
            'term_months' => $this->term_months,
            'payment_amount' => $this->payment_amount,
            'payment_frequency' => $this->payment_frequency,
            'payment_timing' => $this->payment_timing,
            'escalation_type' => $this->escalation_type,
            'escalation_rate_percent' => $this->escalation_rate_percent,
            'incremental_borrowing_rate_percent' => $this->incremental_borrowing_rate_percent,
            'initial_direct_costs' => $this->initial_direct_costs,
            'incentives_received' => $this->incentives_received,
            'purchase_option_price' => $this->purchase_option_price,
            'purchase_option_reasonably_certain' => $this->purchase_option_reasonably_certain,
            'extension_months' => $this->extension_months,
            'extension_reasonably_certain' => $this->extension_reasonably_certain,
            'useful_life_months' => $this->useful_life_months,
            'exemption' => $this->exemption,
            'underlying_asset_value' => $this->underlying_asset_value,
            'rou_asset_account_id' => $this->rou_asset_account_id,
            'accumulated_depreciation_account_id' => $this->accumulated_depreciation_account_id,
            'lease_liability_account_id' => $this->lease_liability_account_id,
            'interest_expense_account_id' => $this->interest_expense_account_id,
            'depreciation_expense_account_id' => $this->depreciation_expense_account_id,
            'status' => $this->status,
            'current_version_number' => $this->current_version_number,
            'effective_term_months' => $this->effectiveTermMonths(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'versions' => LeaseScheduleVersionResource::collection($this->whenLoaded('versions')),
        ];
    }
}
