<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\TimeEntryEventType;
use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TimeEntry
 */
class TimeEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $clockIn = $this->resource->effectiveClockIn();
        $clockOut = $this->resource->effectiveClockOut();
        $breakMinutes = $this->resource->effectiveBreakMinutes();

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'clock_in' => $clockIn->toIso8601String(),
            'clock_out' => $clockOut?->toIso8601String(),
            'duration_hours' => $clockOut
                ? round(max(0, ($clockIn->diffInMinutes($clockOut) - $breakMinutes) / 60.0), 2)
                : null,
            'original' => [
                'clock_in' => $this->clock_in?->toIso8601String(),
                'clock_out' => $this->clock_out?->toIso8601String(),
                'break_minutes' => (int) $this->break_minutes,
            ],
            'applied_correction_id' => $this->applied_correction_id,
            'clock_in_lat' => (float) $this->clock_in_lat,
            'clock_in_lng' => (float) $this->clock_in_lng,
            'is_simplified_record' => (bool) $this->is_simplified_record,
            'break_minutes' => $breakMinutes,
            'is_on_break' => $this->whenLoaded('events', fn (): bool => $this->events->last()?->type === TimeEntryEventType::BreakStarted),
            'events' => TimeEntryEventResource::collection($this->whenLoaded('events')),
            'approved_by' => $this->approved_by,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
