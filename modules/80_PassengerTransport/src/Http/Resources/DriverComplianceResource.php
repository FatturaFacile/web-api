<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\PassengerTransport\Models\DriverComplianceState;

/**
 * Documents with their expiry and the rolling driving counters of a driver.
 *
 * @mixin DriverComplianceState
 */
final class DriverComplianceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'driver_employee_id' => $this->driver_employee_id,
            'driver_name' => $this->whenLoaded('employee', fn (): ?string => $this->employee?->full_name),
            'driving_license_number' => $this->driving_license_number,
            'driving_license_categories' => $this->driving_license_categories ?? [],
            'driving_license_expiry_date' => $this->driving_license_expiry_date?->toDateString(),
            'cqc_persone_number_it' => $this->cqc_persone_number_it,
            'cqc_persone_expiry_date' => $this->cqc_persone_expiry_date?->toDateString(),
            'czv_certificate_number_ch' => $this->czv_certificate_number_ch,
            'czv_expiry_date' => $this->czv_expiry_date?->toDateString(),
            'tachograph_card_number' => $this->tachograph_card_number,
            'tachograph_card_expiry_date' => $this->tachograph_card_expiry_date?->toDateString(),
            'daily_driving_minutes_used' => $this->daily_driving_minutes_used,
            'extended_10h_days_used_this_week' => $this->extended_10h_days_used_this_week,
            'continuous_driving_minutes_since_break' => $this->continuous_driving_minutes_since_break,
            'weekly_driving_minutes_used' => $this->weekly_driving_minutes_used,
            'biweekly_driving_minutes_used' => $this->biweekly_driving_minutes_used,
            'last_daily_rest_duration_minutes' => $this->last_daily_rest_duration_minutes,
            'reduced_9h_rests_used_between_weekly_rests' => $this->reduced_9h_rests_used_between_weekly_rests,
            'last_weekly_rest_ended_at' => $this->last_weekly_rest_ended_at?->toIso8601String(),
            'available_for_next_trip' => $this->available_for_next_trip,
            'next_mandatory_break_or_rest_at' => $this->next_mandatory_break_or_rest_at?->toIso8601String(),
            'counters_updated_at' => $this->counters_updated_at?->toIso8601String(),
        ];
    }
}
