<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ShiftPlanning\Infrastructure\Models\ShiftTemplate;

/**
 * @mixin ShiftTemplate
 */
final class ShiftTemplateResource extends JsonResource
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
            'start_time' => substr($this->start_time, 0, 5),
            'end_time' => substr($this->end_time, 0, 5),
            'break_minutes' => $this->break_minutes,
            'color_hex' => $this->color_hex,
            'night_surcharge_applicable' => $this->night_surcharge_applicable,
            'is_on_call' => $this->is_on_call,
            'is_active' => $this->is_active,
        ];
    }
}
