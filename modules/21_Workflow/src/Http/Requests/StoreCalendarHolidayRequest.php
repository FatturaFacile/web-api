<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One holiday of a business calendar.
 */
final class StoreCalendarHolidayRequest extends FormRequest
{
    /**
     * Authorisation is the `workflow_design` module key of the route group.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'holiday_date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:150'],
            'holiday_type' => ['nullable', 'string', Rule::in(['national', 'cantonal', 'patron_saint', 'company'])],
            'region' => ['nullable', 'string', 'max:10'],
        ];
    }
}
