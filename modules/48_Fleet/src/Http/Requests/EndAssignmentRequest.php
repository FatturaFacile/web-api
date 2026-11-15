<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Requests;

/**
 * The return of a vehicle: date and odometer at the end of an assignment.
 */
final class EndAssignmentRequest extends FleetFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'end_odometer_km' => ['required', 'integer', 'min:0', 'max:9999999'],
        ];
    }
}
