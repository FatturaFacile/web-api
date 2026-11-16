<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Requests;

/**
 * A driver assignment: an employee (or an external driver by name) holds the vehicle from the handover date.
 */
final class AssignmentRequest extends FleetFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'employee_id' => ['nullable', 'uuid'],
            'driver_label' => ['nullable', 'string', 'max:120'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => array_filter(['nullable', 'date_format:Y-m-d', $this->filled('starts_on') ? 'after_or_equal:starts_on' : null]),
            'start_odometer_km' => ['sometimes', 'integer', 'min:0', 'max:9999999'],
            'end_odometer_km' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->isMethod('POST') ? ['vehicle_id' => ['required', 'uuid', $this->ownRow('fleet_vehicles')], ...$rules] : $rules;
    }
}
