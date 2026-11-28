<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\BondedWarehouses\Models\CustomsDischargeMovement;

/**
 * @mixin CustomsDischargeMovement
 */
final class DischargeMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'authorization_id' => $this->authorization_id,
            'mrn_lot_id' => $this->mrn_lot_id,
            'discharge_type' => $this->discharge_type->value,
            'discharge_mrn_number' => $this->discharge_mrn_number,
            'discharge_date' => $this->discharge_date->format('Y-m-d'),
            'allocation_method' => $this->allocation_method->value,
            'discharged_quantity' => $this->discharged_quantity,
            'discharged_net_weight_kg' => $this->discharged_net_weight_kg,
            'released_customs_value' => $this->released_customs_value,
            'paid_duty_amount' => $this->paid_duty_amount,
            'paid_vat_amount' => $this->paid_vat_amount,
            'extinguished_duty_amount' => $this->extinguished_duty_amount,
            'extinguished_vat_amount' => $this->extinguished_vat_amount,
            'cgu_released_amount' => $this->cgu_released_amount,
            'usual_handling_code' => $this->usual_handling_code,
            'usual_handling_notes' => $this->usual_handling_notes,
            'customs_prospectus_document_id' => $this->customs_prospectus_document_id,
            'created_by' => $this->created_by,
        ];
    }
}
