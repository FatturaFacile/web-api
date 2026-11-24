<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Facility\Http\Requests\Concerns\FacilityRules;
use Modules\Facility\Models\FacilityMeter;

/**
 * A utility meter. The tariff is per unit of the meter (kWh, Sm3, m3) in EUR or CHF.
 */
final class MeterRequest extends FormRequest
{
    use FacilityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'building_id' => ['required', 'uuid', $this->tenantOwns('fac_buildings')],
            'space_id' => ['nullable', 'uuid', $this->tenantOwns('fac_spaces')],
            'kind' => ['required', Rule::in(FacilityMeter::Kinds)],
            'code' => ['required', 'string', 'max:40', $this->uniqueInTenant('fac_meters', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'unit' => ['required', 'string', 'max:10'],
            'tariff_per_unit' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['sometimes', Rule::in(FacilityMeter::Currencies)],
            'rollover_value' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999'],
            'is_active' => ['sometimes', 'boolean'],
            'installed_on' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
