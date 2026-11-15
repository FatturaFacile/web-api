<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Requests;

/**
 * The payment of an incident: the payment date, today when left out.
 */
final class PayIncidentRequest extends FleetFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'paid_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
