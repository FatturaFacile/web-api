<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Facility\Models\FacilityBooking;

/**
 * A booking with its space and the person it is for.
 *
 * @mixin FacilityBooking
 */
final class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'space_id' => $this->space_id,
            'series_id' => $this->series_id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn (): ?array => $this->user === null ? null : ['id' => $this->user->id, 'name' => $this->user->name]),
            'space' => $this->whenLoaded('space', fn (): array => ['id' => $this->space->id, 'code' => $this->space->code, 'name' => $this->space->name, 'kind' => $this->space->kind, 'building_id' => $this->space->building_id]),
            'title' => $this->title,
            'attendees' => $this->attendees,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'status' => $this->status,
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
