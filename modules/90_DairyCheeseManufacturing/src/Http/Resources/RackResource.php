<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DairyCheeseManufacturing\Models\AgingRack;

/**
 * The occupied and overdue counts are set by the cellar map as attributes (occupied_count, overdue_count).
 *
 * @mixin AgingRack
 */
final class RackResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'room' => $this->room, 'rack' => $this->rack, 'shelf' => $this->shelf, 'level' => (int) $this->level, 'capacity_wheels' => (int) $this->capacity_wheels,
            'occupied_wheels' => (int) ($this->getAttribute('occupied_count') ?? $this->wheels()->whereIn('status', ['brine', 'aging'])->count()),
            'overdue_care_count' => (int) ($this->getAttribute('overdue_count') ?? 0),
        ];
    }
}
