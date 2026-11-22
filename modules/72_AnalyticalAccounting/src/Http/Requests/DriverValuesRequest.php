<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Requests;

/**
 * Validation of the bulk update of the values of a driver for one period.
 */
final class DriverValuesRequest extends AnalyticalFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period_key' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'values' => ['required', 'array', 'min:1'],
            'values.*.analytical_center_id' => ['required', 'uuid', $this->owned('analytical_centers')],
            'values.*.value' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
        ];
    }
}
