<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Carbon;

/**
 * Payload to create or change a trip.
 */
final class TripRequest extends TransportationFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'carrier_id' => ['nullable', 'uuid', $this->ownRow('tms_carriers')],
            'vehicle_id' => ['nullable', 'uuid', $this->ownRow('fleet_vehicles')],
            'planned_start_at' => ['nullable', 'date'],
            'planned_end_at' => ['nullable', 'date'],
            'capacity_weight_kg' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'capacity_volume_cbm' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'planned_distance_km' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'currency' => ['sometimes', 'string', 'max:3', 'size:3'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return $rules;
    }

    /**
     * Cross-field rules.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $start = $this->valueOf('planned_start_at', 'tms_trips');
            $end = $this->valueOf('planned_end_at', 'tms_trips');

            if ($start !== null && $end !== null && Carbon::parse((string) $end)->lessThan(Carbon::parse((string) $start))) {
                $validator->errors()->add('planned_end_at', __('transportation_errors.trip.ends_before_start'));
            }
        });
    }
}
