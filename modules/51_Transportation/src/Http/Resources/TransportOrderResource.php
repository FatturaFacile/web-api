<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Transportation\Models\TmsTransportOrder;

/**
 * A shipment to move from an origin to a destination within pickup and delivery windows, with the carrier and expected cost once assigned.
 *
 * @mixin TmsTransportOrder
 */
final class TransportOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'reference' => $this->reference,
            'status' => $this->status,
            'origin_name' => $this->origin_name,
            'origin_location' => $this->origin_location,
            'origin_city' => $this->origin_city,
            'origin_zone' => $this->origin_zone,
            'destination_name' => $this->destination_name,
            'destination_location' => $this->destination_location,
            'destination_city' => $this->destination_city,
            'destination_zone' => $this->destination_zone,
            'pickup_from' => $this->pickup_from?->toIso8601String(),
            'pickup_to' => $this->pickup_to?->toIso8601String(),
            'delivery_from' => $this->delivery_from?->toIso8601String(),
            'delivery_to' => $this->delivery_to?->toIso8601String(),
            'weight_kg' => $this->weight_kg,
            'volume_cbm' => $this->volume_cbm,
            'pallets' => $this->pallets,
            'distance_km' => $this->distance_km,
            'goods_description' => $this->goods_description,
            'carrier_id' => $this->carrier_id,
            'currency' => $this->currency,
            'expected_cost' => $this->expected_cost,
            'actual_cost' => $this->actual_cost,
            'trip_id' => $this->trip_id,
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'notes' => $this->notes,
            'carrier' => $this->whenLoaded('carrier', fn (): ?array => $this->carrier !== null ? ['id' => $this->carrier->id, 'code' => $this->carrier->code, 'name' => $this->carrier->name] : null),
            'trip' => $this->whenLoaded('trip', fn (): ?array => $this->trip !== null ? ['id' => $this->trip->id, 'number' => $this->trip->number, 'status' => $this->trip->status] : null),
            'is_on_time' => $this->isOnTime(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
