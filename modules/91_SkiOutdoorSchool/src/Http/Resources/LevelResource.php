<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SkiOutdoorSchool\Models\CourseLevel;

/**
 * @mixin CourseLevel
 */
final class LevelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'system' => $this->system->value,
            'code' => $this->code,
            'label_key' => $this->labelKey(),
            'sort_order' => $this->sort_order,
            'color' => $this->color,
            'is_active' => $this->is_active,
        ];
    }
}
