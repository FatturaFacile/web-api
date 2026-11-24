<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgWorkingCalendar;
use Modules\Manufacturing\Models\MfgWorkingCalendarClosure;
use Modules\Manufacturing\Models\MfgWorkingCalendarShift;

/**
 * A working calendar with its shifts and planned shutdowns (when loaded).
 *
 * @mixin MfgWorkingCalendar
 */
final class MfgWorkingCalendarResource extends JsonResource
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
            'timezone' => $this->timezone,
            'country_code' => $this->country_code,
            'region_code' => $this->region_code,
            'inherit_holiday_calendar_id' => $this->inherit_holiday_calendar_id,
            'is_default' => $this->is_default,
            'shifts' => $this->whenLoaded('shifts', fn () => $this->shifts->map(static fn (MfgWorkingCalendarShift $shift): array => [
                'day_of_week' => $shift->day_of_week,
                'shift_code' => $shift->shift_code,
                'start_time' => $shift->start_time,
                'end_time' => $shift->end_time,
                'crosses_midnight' => $shift->crosses_midnight,
                'break_minutes' => $shift->break_minutes,
                'net_available_minutes' => $shift->net_available_minutes,
                'labor_multiplier' => $shift->labor_multiplier,
            ])->values()->all()),
            'closures' => $this->whenLoaded('closures', fn () => $this->closures->map(static fn (MfgWorkingCalendarClosure $closure): array => [
                'starts_on' => $closure->starts_on->toDateString(),
                'ends_on' => $closure->ends_on->toDateString(),
                'reason' => $closure->reason,
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
