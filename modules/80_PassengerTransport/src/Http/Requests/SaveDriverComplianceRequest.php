<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\PassengerTransport\Http\Requests\Concerns\ScopesToTenant;

/**
 * Licences, professional certificates and the counters of a driver. Counters can be corrected by hand (a .DDD upload refreshes them).
 */
final class SaveDriverComplianceRequest extends FormRequest
{
    use ScopesToTenant;

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
            'driver_employee_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'uuid', $this->tenantExists('hr_employees')],
            'driving_license_number' => ['nullable', 'string', 'max:40'],
            'driving_license_categories' => ['nullable', 'array', 'max:12'],
            'driving_license_categories.*' => ['string', 'in:B,BE,C,CE,D1,D1E,D,DE'],
            'driving_license_expiry_date' => ['nullable', 'date'],
            'cqc_persone_number_it' => ['nullable', 'string', 'max:40'],
            'cqc_persone_expiry_date' => ['nullable', 'date'],
            'czv_certificate_number_ch' => ['nullable', 'string', 'max:40'],
            'czv_expiry_date' => ['nullable', 'date'],
            'tachograph_card_number' => ['nullable', 'string', 'max:20'],
            'tachograph_card_expiry_date' => ['nullable', 'date'],
            'daily_driving_minutes_used' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'extended_10h_days_used_this_week' => ['sometimes', 'integer', 'min:0', 'max:7'],
            'continuous_driving_minutes_since_break' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'weekly_driving_minutes_used' => ['sometimes', 'integer', 'min:0', 'max:10080'],
            'biweekly_driving_minutes_used' => ['sometimes', 'integer', 'min:0', 'max:20160'],
            'last_daily_rest_duration_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'reduced_9h_rests_used_between_weekly_rests' => ['sometimes', 'integer', 'min:0', 'max:7'],
            'last_weekly_rest_ended_at' => ['nullable', 'date'],
        ];
    }
}
