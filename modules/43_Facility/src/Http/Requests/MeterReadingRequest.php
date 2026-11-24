<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Facility\Http\Requests\Concerns\FacilityRules;
use Modules\Facility\Models\FacilityMeterReading;

/**
 * A reading of a meter: the dial value on a date, regular, after a roll-over of the dial or after a meter replacement.
 */
final class MeterReadingRequest extends FormRequest
{
    use FacilityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'read_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'value' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'reading_type' => ['sometimes', Rule::in(FacilityMeterReading::Types)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
