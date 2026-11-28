<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SkiOutdoorSchool\Models\InstructorAvailability;

/**
 * @mixin InstructorAvailability
 */
final class AvailabilityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'instructor_id' => $this->instructor_id,
            'slot_date' => $this->slot_date->toDateString(),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'kind' => $this->kind->value,
            'note' => $this->note,
        ];
    }
}
