<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GreenCare\Models\PhytosanitaryTreatmentLog;

/**
 * @mixin PhytosanitaryTreatmentLog
 */
final class TreatmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'site_name' => $this->site->name ?? null,
            'route_stop_id' => $this->route_stop_id,
            'treatment_date' => $this->treatment_date->format('Y-m-d'),
            'target_pest_or_weed' => $this->target_pest_or_weed,
            'treated_crop_or_surface' => $this->treated_crop_or_surface,
            'treated_area_sqm' => (float) $this->treated_area_sqm,
            'commercial_product_name' => $this->commercial_product_name,
            'registration_number' => $this->registration_number,
            'active_ingredient' => $this->active_ingredient,
            'dosage_used' => (float) $this->dosage_used,
            'dosage_unit' => $this->dosage_unit->value,
            'water_volume_liters' => $this->water_volume_liters === null ? null : (float) $this->water_volume_liters,
            'warehouse_product_id' => $this->warehouse_product_id,
            'warehouse_id' => $this->warehouse_id,
            'quantity_deducted' => (float) $this->quantity_deducted,
            'operator_id' => $this->operator_id,
            'operator_name' => $this->operator->name ?? null,
            'operator_license_id' => $this->operator_license_id,
            'operator_license_type' => $this->license->license_type->value ?? null,
            're_entry_interval_hours' => $this->re_entry_interval_hours,
            're_entry_until' => $this->treatment_date->copy()->startOfDay()->addHours($this->re_entry_interval_hours)->toIso8601String(),
            'weather_conditions_at_application' => $this->weather_conditions_at_application,
            'notes' => $this->notes,
            'jurisdiction' => $this->jurisdiction,
        ];
    }
}
