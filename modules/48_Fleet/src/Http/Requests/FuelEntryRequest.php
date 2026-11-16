<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Fleet\Models\FleetFuelEntry;

/**
 * A refuel or charging session: liters, kWh or kg with the price (unit price, total cost or both) and the odometer.
 */
final class FuelEntryRequest extends FleetFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'employee_id' => ['nullable', 'uuid'],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'energy_unit' => ['required', Rule::in(FleetFuelEntry::Units)],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'total_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'odometer_km' => ['required', 'integer', 'min:0', 'max:9999999'],
            'is_full_tank' => ['sometimes', 'boolean'],
            'station' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->isMethod('POST') ? ['vehicle_id' => ['required', 'uuid', $this->ownRow('fleet_vehicles')], ...$rules] : $rules;
    }
}
