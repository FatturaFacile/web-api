<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GreenCare\Http\Support\ContactName;
use Modules\GreenCare\Models\GreenSpaceSite;

/**
 * @mixin GreenSpaceSite
 */
final class SiteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'customer_id' => $this->customer_id,
            'customer_name' => ContactName::of($this->customer),
            'condominium_administrator_id' => $this->condominium_administrator_id,
            'condominium_administrator_name' => ContactName::of($this->condominiumAdministrator),
            'jurisdiction' => $this->jurisdiction,
            'address' => $this->address,
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,
            'geo_polygon_coordinates' => $this->geo_polygon_coordinates,
            'lawn_area_sqm' => (float) $this->lawn_area_sqm,
            'hedge_length_meters' => (float) $this->hedge_length_meters,
            'tree_count' => $this->tree_count,
            'irrigation_system_specs' => $this->irrigation_system_specs ?? ['zones' => null, 'controller_type' => null, 'winterized' => false, 'notes' => null],
            'access_gate_code' => $this->access_gate_code,
            'access_notes' => $this->access_notes,
            'botanical_inventory' => $this->botanical_inventory ?? [],
            'is_active' => $this->is_active,
        ];
    }
}
