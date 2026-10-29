<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AssetDisposalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'disposal_number' => $this->disposal_number,
            'asset_id' => $this->asset_id,
            'disposal_type' => $this->disposal_type,
            'disposal_date' => $this->disposal_date?->format('Y-m-d'),
            'asset_cost_at_disposal' => (float) $this->asset_cost_at_disposal,
            'accumulated_depreciation_at_disposal' => (float) $this->accumulated_depreciation_at_disposal,
            'carrying_amount_at_disposal' => (float) $this->carrying_amount_at_disposal,
            'proceeds_amount' => (float) $this->proceeds_amount,
            'disposal_costs' => (float) $this->disposal_costs,
            'gain_loss_amount' => (float) $this->gain_loss_amount,
            'status' => $this->status,
            'journal_entry_id' => $this->journal_entry_id,
        ];
    }
}
