<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

/**
 * Validation of a working calendar with its weekly shifts and planned shutdowns (PUT: every field optional; shifts and
 * closures, when sent, replace the existing ones).
 */
final class ConfigureWorkingCalendarRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('PUT') || $this->isMethod('PATCH') ? 'sometimes' : 'required';
        $time = ['required', 'date_format:H:i'];

        return [
            'code' => [$required, 'string', 'max:50', Rule::unique('mfg_working_calendars', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')->ignore($this->route('id'))],
            'name' => [$required, 'string', 'max:200'],
            'timezone' => ['sometimes', 'timezone'],
            'country_code' => ['sometimes', 'string', 'in:CH,IT,ch,it'],
            'region_code' => ['nullable', 'string', 'alpha', 'min:2', 'max:3'],
            'inherit_holiday_calendar_id' => ['nullable', 'uuid', $this->tenantOwns('hr_holiday_calendars')],
            'is_default' => ['sometimes', 'boolean'],
            'shifts' => ['sometimes', 'array', 'max:60'],
            'shifts.*.day_of_week' => ['required', 'integer', 'between:1,7'],
            'shifts.*.shift_code' => ['required', 'string', 'max:30'],
            'shifts.*.start_time' => $time,
            'shifts.*.end_time' => $time,
            'shifts.*.break_minutes' => ['nullable', 'integer', 'min:0', 'max:720'],
            'shifts.*.labor_multiplier' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:10'],
            'closures' => ['sometimes', 'array', 'max:200'],
            'closures.*.starts_on' => ['required', 'date'],
            'closures.*.ends_on' => ['required', 'date', 'after_or_equal:closures.*.starts_on'],
            'closures.*.reason' => ['nullable', 'string', 'max:200'],
        ];
    }
}
