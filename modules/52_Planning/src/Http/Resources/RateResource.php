<?php

declare(strict_types=1);

namespace Modules\Planning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Planning\Models\PlanningItemResourceRate;

/**
 * The capacity an item consumes on a resource per unit supplied.
 *
 * @mixin PlanningItemResourceRate
 */
final class RateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'resource_id' => $this->resource_id,
            'item_code' => $this->item_code,
            'load_per_unit' => $this->load_per_unit,
        ];
    }
}
