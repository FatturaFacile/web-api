<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\BondedWarehouses\Models\BondedMrnLot;

/**
 * @mixin BondedMrnLot
 */
final class MrnLotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'authorization_id' => $this->authorization_id,
            'authorization_code' => $this->whenLoaded('authorization', fn (): string => $this->authorization->authorization_code),
            'regime_type' => $this->whenLoaded('authorization', fn (): string => $this->authorization->regime_type->value),
            'currency' => $this->whenLoaded('authorization', fn (): string => $this->authorization->currency),
            'mrn_number' => $this->mrn_number,
            'mrn_item_number' => $this->mrn_item_number,
            'declaration_type' => $this->declaration_type->value,
            'entry_date' => $this->entry_date->format('Y-m-d'),
            'product_id' => $this->product_id,
            'wms_lot_id' => $this->wms_lot_id,
            'taric_hs_code' => $this->taric_hs_code,
            'country_of_origin' => $this->country_of_origin,
            'preferential_origin' => $this->preferential_origin,
            'preferential_agreement_code' => $this->preferential_agreement_code,
            'initial_quantity' => $this->initial_quantity,
            'remaining_quantity' => $this->remaining_quantity,
            'unit_of_measure' => $this->unit_of_measure,
            'initial_net_weight_kg' => $this->initial_net_weight_kg,
            'remaining_net_weight_kg' => $this->remaining_net_weight_kg,
            'initial_gross_weight_kg' => $this->initial_gross_weight_kg,
            'remaining_gross_weight_kg' => $this->remaining_gross_weight_kg,
            'customs_statistical_value' => $this->customs_statistical_value,
            'duty_rate_percent' => $this->duty_rate_percent,
            'vat_rate_percent' => $this->vat_rate_percent,
            'suspended_duty_amount' => $this->suspended_duty_amount,
            'suspended_vat_amount' => $this->suspended_vat_amount,
            'cgu_engaged_amount' => $this->cgu_engaged_amount,
            'customs_status' => $this->customs_status->value,
            'discharges' => DischargeMovementResource::collection($this->whenLoaded('discharges')),
        ];
    }
}
