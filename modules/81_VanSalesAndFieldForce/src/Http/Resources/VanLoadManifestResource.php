<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\VanSalesAndFieldForce\Models\VanLoadManifest;

/**
 * @mixin VanLoadManifest
 */
final class VanLoadManifestResource extends JsonResource
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
            'van_name' => $this->whenLoaded('vanWarehouse', fn (): ?string => $this->vanWarehouse?->name),
            'manifest_number' => $this->manifest_number,
            'load_date' => $this->load_date->toDateString(),
            'weekday_iso' => $this->weekday_iso,
            'route_code' => $this->route_code,
            'depot_warehouse_id' => $this->depot_warehouse_id,
            'global_ddt_id' => $this->global_ddt_id,
            'global_ddt_number' => $this->global_ddt_number,
            'global_ddt_causal' => $this->global_ddt_causal,
            'regulatory_reference' => $this->regulatory_reference,
            'self_addressed_company_name' => $this->self_addressed_company_name,
            'departure_temp_celsius' => $this->departure_temp_celsius,
            'cold_chain_compliant' => $this->cold_chain_compliant,
            'status' => $this->status->value,
            'lines' => $this->lines ?? [],
            'replenishment_suggestions' => $this->replenishment_suggestions ?? [],
            'empties_loaded' => $this->empties_loaded ?? [],
            'total_packages' => $this->total_packages,
            'total_gross_weight_kg' => $this->total_gross_weight_kg,
            'total_net_value' => $this->total_net_value,
            'currency' => $this->currency,
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
