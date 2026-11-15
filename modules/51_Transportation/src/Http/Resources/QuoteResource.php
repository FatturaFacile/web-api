<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Transportation\Models\TmsQuote;

/**
 * A comparison of the active carriers for a shipment: the offers ranked cheapest first, and the carrier it was awarded to.
 *
 * @mixin TmsQuote
 */
final class QuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'transport_order_id' => $this->transport_order_id,
            'ship_date' => $this->ship_date->toDateString(),
            'weight_kg' => $this->weight_kg,
            'distance_km' => $this->distance_km,
            'origin_zone' => $this->origin_zone,
            'destination_zone' => $this->destination_zone,
            'currency' => $this->currency,
            'status' => $this->status,
            'offers' => $this->offers,
            'awarded_carrier_id' => $this->awarded_carrier_id,
            'awarded_amount' => $this->awarded_amount,
            'awarded_at' => $this->awarded_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
