<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AssetManagement\Models\AssetValuation;

/**
 * A revaluation, impairment or impairment reversal of an asset. Amounts are decimal strings.
 *
 * @mixin AssetValuation
 */
final class AssetValuationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'valuation_number' => $this->valuation_number,
            'valuation_type' => $this->valuation_type,
            'valuation_date' => $this->valuation_date?->format('Y-m-d'),
            'carrying_amount_before' => $this->carrying_amount_before !== null ? (string) $this->carrying_amount_before : null,
            'fair_value' => $this->fair_value !== null ? (string) $this->fair_value : null,
            'value_in_use' => $this->value_in_use !== null ? (string) $this->value_in_use : null,
            'recoverable_amount' => $this->recoverable_amount !== null ? (string) $this->recoverable_amount : null,
            'adjustment_amount' => $this->adjustment_amount !== null ? (string) $this->adjustment_amount : null,
            'revaluation_reserve_delta' => $this->revaluation_reserve_delta !== null ? (string) $this->revaluation_reserve_delta : null,
            'impairment_loss_amount' => $this->impairment_loss_amount !== null ? (string) $this->impairment_loss_amount : null,
            'carrying_amount_after' => $this->carrying_amount_after !== null ? (string) $this->carrying_amount_after : null,
            'status' => $this->status,
            'journal_entry_id' => $this->journal_entry_id,
            'is_locked' => (bool) $this->is_locked,
            'appraiser_name' => $this->appraiser_name,
            'reason' => $this->reason,
        ];
    }
}
