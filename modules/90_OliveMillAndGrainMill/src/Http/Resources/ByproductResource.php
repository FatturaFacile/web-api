<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\OliveMillAndGrainMill\Models\ByproductMovement;

/**
 * @mixin ByproductMovement
 */
final class ByproductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'byproduct_type' => $this->byproduct_type->value,
            'movement_date' => $this->movement_date->format('Y-m-d'),
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'delivery_id' => $this->delivery_id,
            'cycle_id' => $this->cycle_id,
            'counterparty_contact_id' => $this->counterparty_contact_id,
            'counterparty_name' => $this->counterparty_name,
            'destination_type' => $this->destination_type,
            'transport_document' => $this->transport_document,
            'cadastral_parcel' => $this->cadastral_parcel,
            'parcel_area_ha' => $this->parcel_area_ha,
            'm3_per_ha' => $this->m3_per_ha,
            'notes' => $this->notes,
        ];
    }
}
