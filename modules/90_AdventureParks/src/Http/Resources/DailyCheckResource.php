<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AdventureParks\Models\DailyCourseCheck;

/**
 * @mixin DailyCourseCheck
 */
final class DailyCheckResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'check_date' => $this->check_date->toDateString(),
            'checked_by_name' => $this->checked_by_name,
            'items' => $this->items,
            'outcome' => $this->outcome,
            'signature' => $this->signature,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
