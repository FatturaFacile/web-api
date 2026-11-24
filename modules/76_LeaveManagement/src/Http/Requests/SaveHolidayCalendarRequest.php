<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\LeaveManagement\Domain\Enums\LeaveCountry;
use Modules\LeaveManagement\Domain\Enums\SundayHolidayRule;
use Modules\LeaveManagement\Models\HolidayCalendar;

final class SaveHolidayCalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manageConfiguration', HolidayCalendar::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:160'],
            'country' => ['required', Rule::enum(LeaveCountry::class)],
            'canton_or_province_code' => ['nullable', 'string', 'max:20'],
            'municipality_name' => ['nullable', 'string', 'max:120'],
            'municipality_patron_saint_date' => ['nullable', 'regex:/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/'],
            'municipality_patron_saint_name' => ['nullable', 'string', 'max:120'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'sunday_holiday_rule' => ['nullable', Rule::enum(SundayHolidayRule::class)],
            'holidays' => ['nullable', 'array'],
            'working_days' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
