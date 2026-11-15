<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Fleet\Models\FleetServiceRecord;

/**
 * A service or maintenance done on a vehicle, optionally completing a scheduled service.
 */
final class ServiceRecordRequest extends FleetFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'scheduled_service_id' => ['nullable', 'uuid'],
            'service_type' => ['sometimes', Rule::in(FleetServiceRecord::Types)],
            'service_date' => ['required', 'date_format:Y-m-d'],
            'odometer_km' => ['required', 'integer', 'min:0', 'max:9999999'],
            'vendor' => ['nullable', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:80'],
            'cost' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->isMethod('POST') ? ['vehicle_id' => ['required', 'uuid', $this->ownRow('fleet_vehicles')], ...$rules] : $rules;
    }
}
