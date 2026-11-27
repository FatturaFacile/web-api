<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\GreenCare\Domain\Enums\DosageUnit;
use Modules\GreenCare\Domain\Support\DoseCalculator;

/**
 * A treatment of the phytosanitary diary. The license of the operator and the shape of the registration number are checked by the command.
 */
final class StoreTreatmentRequest extends GreenCareRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $needsWater = DosageUnit::tryFrom((string) $this->input('dosage_unit')) !== null
            && DoseCalculator::needsWaterVolume(DosageUnit::from((string) $this->input('dosage_unit')))
            && $this->filled('warehouse_product_id');

        return [
            'site_id' => ['required', 'uuid', $this->inTenant('green_space_sites')],
            'route_stop_id' => ['nullable', 'uuid', $this->inTenant('crew_route_stops')],
            'treatment_date' => ['required', 'date', 'before_or_equal:today'],
            'target_pest_or_weed' => ['required', 'string', 'max:190'],
            'treated_crop_or_surface' => ['required', 'string', 'max:190'],
            'treated_area_sqm' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'commercial_product_name' => ['required', 'string', 'max:190'],
            'registration_number' => ['required', 'string', 'max:60'],
            'active_ingredient' => ['required', 'string', 'max:190'],
            'dosage_used' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'dosage_unit' => ['required', Rule::in(DosageUnit::values())],
            'water_volume_liters' => [$needsWater ? 'required' : 'nullable', 'numeric', 'gt:0', 'max:99999999'],
            'warehouse_product_id' => ['nullable', 'uuid', $this->inTenant('products')],
            'warehouse_id' => ['nullable', 'uuid', $this->inTenant('warehouses')],
            'operator_id' => ['required', 'uuid', $this->inTenant('crew_team_members')],
            're_entry_interval_hours' => ['nullable', 'integer', 'min:0', 'max:8760'],
            'weather_conditions_at_application' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
