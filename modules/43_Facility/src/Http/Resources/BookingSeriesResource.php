<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Facility\Models\FacilityBookingSeries;

/**
 * A recurring booking.
 *
 * @mixin FacilityBookingSeries
 */
final class BookingSeriesResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'space_id' => $this->space_id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'recurrence' => $this->recurrence,
            'weekdays' => $this->weekdays,
            'start_date' => $this->start_date?->toDateString(),
            'until_date' => $this->until_date?->toDateString(),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'status' => $this->status,
            'occurrences' => $this->occurrences,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
