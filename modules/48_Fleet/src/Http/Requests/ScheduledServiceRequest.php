<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Fleet\Models\FleetServiceRecord;

/**
 * A scheduled service due by date, by kilometres or both. Due values left out are computed from the intervals.
 */
final class ScheduledServiceRequest extends FleetFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'name' => ['required', 'string', 'max:160'],
            'service_type' => ['sometimes', Rule::in(FleetServiceRecord::Types)],
            'interval_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'interval_km' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'last_service_on' => ['nullable', 'date_format:Y-m-d'],
            'last_service_km' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'next_due_on' => ['nullable', 'date_format:Y-m-d'],
            'next_due_km' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->isMethod('POST') ? ['vehicle_id' => ['required', 'uuid', $this->ownRow('fleet_vehicles')], ...$rules] : $rules;
    }
}
