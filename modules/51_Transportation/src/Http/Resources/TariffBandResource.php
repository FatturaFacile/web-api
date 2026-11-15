<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Transportation\Models\TmsTariffBand;

/**
 * A price line of a tariff table for a zone pair, a weight range and a distance range (from inclusive, to exclusive, empty to means open ended). Bands of the same zones never overlap.
 *
 * @mixin TmsTariffBand
 */
final class TariffBandResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tariff_table_id' => $this->tariff_table_id,
            'origin_zone' => $this->origin_zone,
            'destination_zone' => $this->destination_zone,
            'weight_from_kg' => $this->weight_from_kg,
            'weight_to_kg' => $this->weight_to_kg,
            'distance_from_km' => $this->distance_from_km,
            'distance_to_km' => $this->distance_to_km,
            'fixed_amount' => $this->fixed_amount,
            'price_per_kg' => $this->price_per_kg,
            'price_per_km' => $this->price_per_km,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
