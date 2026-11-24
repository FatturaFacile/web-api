<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ItalianVat\Models\ThirdPartyGoodsMovement;

/**
 * @mixin ThirdPartyGoodsMovement
 */
final class ThirdPartyGoodsMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'section_id' => $this->section_id,
            'year' => $this->year,
            'protocol_number' => $this->protocol_number,
            'movement_date' => $this->movement_date->toDateString(),
            'movement_type' => $this->movement_type,
            'contact_id' => $this->contact_id,
            'description' => $this->description,
            'unit' => $this->unit,
            'quantity' => (string) $this->quantity,
            'value' => (string) $this->value,
            'document_number' => $this->document_number,
            'document_date' => $this->document_date?->toDateString(),
            'print_id' => $this->print_id,
            'notes' => $this->notes,
        ];
    }
}
