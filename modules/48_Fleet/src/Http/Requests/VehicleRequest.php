<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Fleet\Models\FleetVehicle;

/**
 * A company vehicle.
 */
final class VehicleRequest extends FleetFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'license_plate' => ['required', 'string', 'max:20'],
            'vin' => ['nullable', 'string', 'max:32'],
            'make' => ['required', 'string', 'max:80'],
            'model' => ['required', 'string', 'max:80'],
            'model_year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'vehicle_type' => ['sometimes', Rule::in(FleetVehicle::Types)],
            'fuel_type' => ['sometimes', Rule::in(FleetVehicle::FuelTypes)],
            'status' => ['sometimes', Rule::in(FleetVehicle::Statuses)],
            'odometer_km' => ['sometimes', 'integer', 'min:0', 'max:9999999'],
            'ownership' => ['sometimes', Rule::in(FleetVehicle::Ownerships)],
            'acquired_on' => ['nullable', 'date_format:Y-m-d'],
            'contract_ends_on' => array_filter(['nullable', 'date_format:Y-m-d', $this->filled('acquired_on') ? 'after_or_equal:acquired_on' : null]),
            'purchase_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'asset_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
