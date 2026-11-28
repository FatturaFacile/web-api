<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DairyCheeseManufacturing\Models\MilkDelivery;

/**
 * @mixin MilkDelivery
 */
final class DeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'breeder_id' => $this->breeder_id, 'breeder_name' => $this->breeder->name, 'tank_id' => $this->tank_id, 'tank_code' => $this->tank->code,
            'route_code' => $this->route_code, 'tanker_plate' => $this->tanker_plate, 'collection_date' => $this->collection_date->toDateString(), 'session' => $this->session,
            'liters' => $this->liters, 'density' => $this->density, 'kg' => $this->kg, 'temperature_c' => $this->temperature_c, 'status' => $this->status,
            'monthly_settlement_id' => $this->monthly_settlement_id, 'notes' => $this->notes,
        ];
    }
}
