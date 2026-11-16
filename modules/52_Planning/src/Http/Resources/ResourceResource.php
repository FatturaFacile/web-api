<?php

declare(strict_types=1);

namespace Modules\Planning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Planning\Models\PlanningResource;

/**
 * A capacity resource with the load rates of its items when loaded.
 *
 * @mixin PlanningResource
 */
final class ResourceResource extends JsonResource
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
            'work_center_id' => $this->work_center_id,
            'unit' => $this->unit,
            'default_capacity' => $this->default_capacity,
            'is_active' => $this->is_active,
            'rates' => RateResource::collection($this->whenLoaded('rates')),
        ];
    }
}
