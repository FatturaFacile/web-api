<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AdventureParks\Models\SlotTemplate;

/**
 * @mixin SlotTemplate
 */
final class SlotTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'duration_minutes' => $this->window()->durationMinutes(),
            'wristband_color' => $this->wristband_color,
            'weekdays' => $this->weekdays,
            'is_active' => $this->is_active,
        ];
    }
}
