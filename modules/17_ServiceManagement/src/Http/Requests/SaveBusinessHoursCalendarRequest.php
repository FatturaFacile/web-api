<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceManagement\Services\Helpdesk\HolidayCatalog;

/**
 * Business hours of the tenant: a weekly schedule of windows per day ("mon" => [["08:00", "12:00"], ["13:00", "17:00"]])
 * in a time zone, holidays as dates (Y-m-d, or m-d every year), and optionally an HR holiday calendar to reuse.
 */
final class SaveBusinessHoursCalendarRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:60', Rule::unique('esm_business_hours_calendars', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')->ignore($this->route('id'))],
            'name' => [$required, 'string', 'max:150'],
            'timezone' => [$required, 'timezone:all'],
            'weekly_schedule' => [$required, 'array'],
            'weekly_schedule.*' => ['array'],
            'weekly_schedule.*.*' => ['array', 'size:2'],
            'weekly_schedule.*.*.*' => ['string', 'date_format:H:i'],
            'holidays' => ['sometimes', 'array', 'max:400'],
            'holidays.*' => ['string', 'regex:/^(\d{4}-)?\d{2}-\d{2}$/'],
            'hr_holiday_calendar_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('hr_holiday_calendars')],
            'holiday_country' => ['sometimes', 'nullable', 'string', Rule::in(HolidayCatalog::Countries)],
            'holiday_region' => ['sometimes', 'nullable', 'string', 'max:8'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function ($validator): void {
            $region = $this->input('holiday_region');

            if ($region !== null && $region !== '' && ! app(HolidayCatalog::class)->supports((string) $this->input('holiday_country'), (string) $region)) {
                $validator->errors()->add('holiday_region', 'Unknown canton or region for the holiday country.');
            }

            foreach ((array) $this->input('weekly_schedule', []) as $day => $windows) {
                if (! in_array(strtolower((string) $day), ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], true)) {
                    $validator->errors()->add("weekly_schedule.{$day}", 'Days are mon, tue, wed, thu, fri, sat and sun.');
                }

                foreach ((array) $windows as $index => $window) {
                    if (is_array($window) && count($window) === 2 && is_string($window[0] ?? null) && is_string($window[1] ?? null) && $window[0] >= $window[1]) {
                        $validator->errors()->add("weekly_schedule.{$day}.{$index}", 'A window must close after it opens.');
                    }
                }
            }
        }];
    }
}
