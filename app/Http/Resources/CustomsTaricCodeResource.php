<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CustomsTaricCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomsTaricCode
 */
class CustomsTaricCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'level' => $this->level->value,
            'chapter' => $this->chapter,
            'description' => $this->description,
            'supplementary_unit' => $this->supplementary_unit,
            'duty_rate_notes' => $this->duty_rate_notes,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
