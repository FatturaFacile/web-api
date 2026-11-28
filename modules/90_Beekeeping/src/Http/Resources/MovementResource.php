<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Beekeeping\Models\NomadismMovement;

/**
 * @mixin NomadismMovement
 */
final class MovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'kind' => $this->kind,
            'origin_apiary_id' => $this->origin_apiary_id,
            'destination_apiary_id' => $this->destination_apiary_id,
            'destination_label' => $this->destination_label,
            'destination_registry_code' => $this->destination_registry_code,
            'counterpart' => $this->counterpart,
            'target_flora' => $this->target_flora,
            'subject' => $this->subject,
            'quantity' => $this->quantity,
            'vehicle_plate' => $this->vehicle_plate,
            'departure_date' => $this->departure_date->toDateString(),
            'arrival_date' => $this->arrival_date?->toDateString(),
            'return_date' => $this->return_date?->toDateString(),
            'status' => $this->status,
            'notes' => $this->notes,
        ];
    }
}
