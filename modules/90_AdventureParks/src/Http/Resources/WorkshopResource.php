<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AdventureParks\Models\EducationalWorkshop;

/**
 * @mixin EducationalWorkshop
 */
final class WorkshopResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'duration_minutes' => $this->duration_minutes,
            'max_participants' => $this->max_participants,
            'price_per_student' => (float) $this->price_per_student,
            'is_active' => $this->is_active,
            'name' => $this->localizedName(app()->getLocale()),
            'name_translations' => $this->name_translations ?? [],
            'description_translations' => $this->description_translations ?? [],
            'is_standard' => $this->tenant_id === null,
        ];
    }
}
