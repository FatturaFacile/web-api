<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Facility\Http\Requests\Concerns\FacilityRules;
use Modules\Facility\Models\FacilityBookingSeries;

/**
 * A recurring booking: daily, or weekly on the given ISO weekdays (1 Monday to 7 Sunday), at the same wall clock time of
 * the company. skip_conflicts books the free occurrences and reports the others instead of refusing the whole series.
 */
final class BookingSeriesRequest extends FormRequest
{
    use FacilityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'space_id' => ['required', 'uuid', $this->tenantOwns('fac_spaces')],
            'user_id' => ['nullable', 'uuid', $this->tenantMember()],
            'recurrence' => ['required', Rule::in(FacilityBookingSeries::Recurrences)],
            'weekdays' => ['required_if:recurrence,weekly', 'nullable', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['integer', 'between:1,7', 'distinct'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'until_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'title' => ['nullable', 'string', 'max:150'],
            'skip_conflicts' => ['sometimes', 'boolean'],
        ];
    }
}
