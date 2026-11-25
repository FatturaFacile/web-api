<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FranchiseNetwork\Models\CatalogPush;

/**
 * @mixin CatalogPush
 */
final class PushResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'push_type' => $this->push_type->value,
            'title' => $this->title,
            'target_mode' => $this->target_mode,
            'status' => $this->status->value,
            'effective_from' => $this->effective_from?->toDateString(),
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'payload' => $this->when($request->routeIs('franchise-network.pushes.show'), $this->payload),
            'targets' => TargetResource::collection($this->whenLoaded('targets')),
            'targets_total' => $this->whenCounted('targets'),
        ];
    }
}
