<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Requests;

/**
 * A measuring instrument with its range, tolerance and calibration interval.
 */
final class InstrumentRequest extends QualityFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:190'],
            'serial_number' => ['nullable', 'string', 'max:80'],
            'manufacturer' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:120'],
            'unit' => ['nullable', 'string', 'max:20'],
            'range_min' => ['nullable', 'numeric', 'between:-999999999,999999999'],
            'range_max' => ['nullable', 'numeric', 'between:-999999999,999999999', 'gte:range_min'],
            'tolerance' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'calibration_interval_days' => ['required', 'integer', 'between:1,3650'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
