<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\IndustrialLaundry\Models\WashBatch;

/**
 * A wash batch with its UNI EN 14065 RABC certificate.
 *
 * @mixin WashBatch
 */
final class WashBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_code' => $this->batch_code,
            'disinfection_thermal_chem_program_code' => $this->disinfection_thermal_chem_program_code,
            'rabc_risk_zone' => $this->rabc_risk_zone->value,
            'rabc_control_status' => $this->rabc_control_status->value,
            'target_temperature_c' => (string) $this->target_temperature_c,
            'target_hold_minutes' => $this->target_hold_minutes,
            'target_chemical_dosing_ml_per_kg' => $this->target_chemical_dosing_ml_per_kg !== null ? (string) $this->target_chemical_dosing_ml_per_kg : null,
            'measured_temperature_c' => $this->measured_temperature_c !== null ? (string) $this->measured_temperature_c : null,
            'measured_hold_minutes' => $this->measured_hold_minutes,
            'measured_chemical_dosing_ml_per_kg' => $this->measured_chemical_dosing_ml_per_kg !== null ? (string) $this->measured_chemical_dosing_ml_per_kg : null,
            'microbiological_cfu_per_25cm2' => $this->microbiological_cfu_per_25cm2 !== null ? (string) $this->microbiological_cfu_per_25cm2 : null,
            'load_net_weight_kg' => (string) $this->load_net_weight_kg,
            'garments_count' => $this->garments_count,
            'operator_name' => $this->operator_name,
            'verifier_name' => $this->verifier_name,
            'violations' => $this->violations ?? [],
            'washed_at' => $this->washed_at?->toIso8601String(),
            'certified_at' => $this->certified_at?->toIso8601String(),
            'scan_session_ids' => $this->relationLoaded('sessions') ? $this->sessions->pluck('id')->map(static fn ($id): string => (string) $id)->values()->all() : null,
            'garment_ids' => $this->relationLoaded('garments') ? $this->garments->pluck('id')->map(static fn ($id): string => (string) $id)->values()->all() : null,
        ];
    }
}
