<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Fleet\Models\FleetDeadline;

/**
 * A compliance deadline of a vehicle: road tax, insurance, inspection.
 */
final class DeadlineRequest extends FleetFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'deadline_type' => ['required', Rule::in(FleetDeadline::Types)],
            'provider' => ['nullable', 'string', 'max:160'],
            'reference' => ['nullable', 'string', 'max:80'],
            'expires_on' => ['required', 'date_format:Y-m-d'],
            'cost' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->isMethod('POST') ? ['vehicle_id' => ['required', 'uuid', $this->ownRow('fleet_vehicles')], ...$rules] : $rules;
    }
}
