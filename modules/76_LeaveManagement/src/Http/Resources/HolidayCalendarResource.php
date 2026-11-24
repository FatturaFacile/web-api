<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\LeaveManagement\Models\HolidayCalendar;

/**
 * @mixin HolidayCalendar
 */
final class HolidayCalendarResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'country' => $this->country,
            'canton_or_province_code' => $this->canton_or_province_code,
            'municipality_name' => $this->municipality_name,
            'municipality_patron_saint_date' => $this->municipality_patron_saint_date,
            'municipality_patron_saint_name' => $this->municipality_patron_saint_name,
            'year' => $this->year,
            'sunday_holiday_rule' => $this->sunday_holiday_rule,
            'holidays' => $this->holidays ?? [],
            'working_days' => $this->working_days ?? ['MON', 'TUE', 'WED', 'THU', 'FRI'],
            'is_active' => (bool) $this->is_active,
        ];
    }
}
