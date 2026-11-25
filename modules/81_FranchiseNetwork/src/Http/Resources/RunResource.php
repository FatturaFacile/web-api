<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FranchiseNetwork\Models\RoyaltyRun;

/**
 * @mixin RoyaltyRun
 */
final class RunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period' => $this->period,
            'status' => $this->status->value,
            'stores_count' => $this->stores_count,
            'total_royalty' => $this->total_royalty,
            'total_marketing' => $this->total_marketing,
            'total_minimum_adjustment' => $this->total_minimum_adjustment,
            'total_loyalty_settlement' => $this->total_loyalty_settlement,
            'total_net' => $this->total_net,
            'currency' => $this->currency,
            'calculated_at' => $this->calculated_at?->toIso8601String(),
            'invoiced_at' => $this->invoiced_at?->toIso8601String(),
            'statements' => StatementResource::collection($this->whenLoaded('statements')),
            'batches' => CollectionBatchResource::collection($this->whenLoaded('batches')),
        ];
    }
}
