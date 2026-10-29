<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AssetValuationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'valuation_number' => $this->valuation_number,
            'asset_id' => $this->asset_id,
            'valuation_type' => $this->valuation_type,
            'valuation_date' => $this->valuation_date?->format('Y-m-d'),
            'carrying_amount_before' => (float) $this->carrying_amount_before,
            'fair_value' => (float) $this->fair_value,
            'value_in_use' => (float) $this->value_in_use,
            'recoverable_amount' => (float) $this->recoverable_amount,
            'adjustment_amount' => (float) $this->adjustment_amount,
            'revaluation_reserve_delta' => (float) $this->revaluation_reserve_delta,
            'impairment_loss_amount' => (float) $this->impairment_loss_amount,
            'carrying_amount_after' => (float) $this->carrying_amount_after,
            'status' => $this->status,
            'journal_entry_id' => $this->journal_entry_id,
        ];
    }
}
