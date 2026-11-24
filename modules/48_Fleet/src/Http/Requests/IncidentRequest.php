<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Fleet\Models\FleetIncident;

/**
 * A traffic fine, accident or damage. The driver is attributed from the assignment on the day when it is left out.
 */
final class IncidentRequest extends FleetFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'employee_id' => ['nullable', 'uuid'],
            'kind' => ['sometimes', Rule::in(FleetIncident::Kinds)],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:80'],
            'description' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:160'],
            'amount' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'payment_status' => ['sometimes', Rule::in(FleetIncident::PaymentStatuses)],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'paid_on' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->isMethod('POST') ? ['vehicle_id' => ['required', 'uuid', $this->ownRow('fleet_vehicles')], ...$rules] : $rules;
    }
}
