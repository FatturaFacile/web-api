<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Transportation\Models\TmsTariffTable;

/**
 * A price list of a carrier valid for a period. The rate basis says whether the price follows weight, distance, both or is flat; bands hold the prices.
 *
 * @mixin TmsTariffTable
 */
final class TariffTableResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'carrier_id' => $this->carrier_id,
            'name' => $this->name,
            'currency' => $this->currency,
            'valid_from' => $this->valid_from->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'rate_basis' => $this->rate_basis,
            'fuel_surcharge_percent' => $this->fuel_surcharge_percent,
            'minimum_charge' => $this->minimum_charge,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'carrier' => $this->whenLoaded('carrier', fn (): ?array => $this->carrier !== null ? ['id' => $this->carrier->id, 'code' => $this->carrier->code, 'name' => $this->carrier->name] : null),
            'band_count' => $this->whenCounted('bands'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
