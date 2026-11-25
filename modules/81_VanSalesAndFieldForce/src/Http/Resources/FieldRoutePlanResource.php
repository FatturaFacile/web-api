<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\VanSalesAndFieldForce\Models\FieldRoutePlan;

/**
 * @mixin FieldRoutePlan
 */
final class FieldRoutePlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'van_warehouse_id' => $this->van_warehouse_id,
            'van_license_plate' => $this->whenLoaded('vanWarehouse', fn (): ?string => $this->vanWarehouse?->license_plate),
            'van_load_manifest_id' => $this->van_load_manifest_id,
            'route_code' => $this->route_code,
            'name' => $this->name,
            'plan_date' => $this->plan_date->toDateString(),
            'weekday_iso' => $this->weekday_iso,
            'is_recurring' => $this->is_recurring,
            'salesperson_user_id' => $this->salesperson_user_id,
            'salesperson_name' => $this->salesperson_name,
            'territory_zone' => $this->territory_zone,
            'country' => $this->country,
            'status' => $this->status,
            'stops' => $this->stops ?? [],
            'total_stops' => $this->total_stops,
            'completed_stops' => $this->completed_stops,
            'sync_version' => $this->sync_version,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
