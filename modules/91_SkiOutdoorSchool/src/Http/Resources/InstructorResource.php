<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SkiOutdoorSchool\Models\Instructor;

/**
 * @mixin Instructor
 */
final class InstructorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'email' => $this->email,
            'phone' => $this->phone,
            'license_type' => $this->license_type->value,
            'license_number' => $this->license_number,
            'license_valid_until' => $this->license_valid_until?->toDateString(),
            'hourly_rate' => $this->hourly_rate,
            'retention_mode' => $this->retention_mode->value,
            'retention_value' => $this->retention_value,
            'collaboration_regime' => $this->collaboration_regime->value,
            'withholding_percent' => $this->withholding_percent,
            'max_weekly_hours' => $this->max_weekly_hours,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'languages' => $this->relationLoaded('languages') ? $this->languages->map(static fn ($row): array => ['code' => $row->language_code, 'proficiency' => $row->proficiency])->values()->all() : [],
            'disciplines' => $this->relationLoaded('disciplines') ? $this->disciplineValues() : [],
            'availabilities' => $this->relationLoaded('availabilities') ? AvailabilityResource::collection($this->availabilities) : [],
        ];
    }
}
