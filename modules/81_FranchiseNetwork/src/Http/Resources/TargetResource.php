<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FranchiseNetwork\Models\CatalogPushTarget;

/**
 * @mixin CatalogPushTarget
 */
final class TargetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'push_id' => $this->push_id,
            'store_id' => $this->store_id,
            'store_code' => $this->store?->code,
            'store_name' => $this->store?->name,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'last_error' => $this->last_error,
            'synced_at' => $this->synced_at?->toIso8601String(),
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'next_retry_at' => $this->next_retry_at?->toIso8601String(),
        ];
    }
}
