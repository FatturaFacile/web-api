<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AdventureParks\Models\PpeAsset;

/**
 * @mixin PpeAsset
 */
final class PpeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $today = CarbonImmutable::now();
        $reason = $this->blockedReason($today);

        return [
            'id' => $this->id,
            'serial_code' => $this->serial_code,
            'category' => $this->category,
            'size' => $this->size,
            'min_height_cm' => $this->min_height_cm,
            'max_height_cm' => $this->max_height_cm,
            'min_weight_kg' => $this->min_weight_kg,
            'max_weight_kg' => $this->max_weight_kg,
            'brand' => $this->brand,
            'model' => $this->model,
            'batch_number' => $this->batch_number,
            'purchase_date' => $this->purchase_date?->toDateString(),
            'first_use_date' => $this->first_use_date?->toDateString(),
            'manufacturer_max_lifespan_date' => $this->manufacturer_max_lifespan_date->toDateString(),
            'rental_cycle_count' => $this->rental_cycle_count,
            'cumulative_usage_hours' => (float) $this->cumulative_usage_hours,
            'last_inspection_date' => $this->last_inspection_date?->toDateString(),
            'next_inspection_due_date' => $this->next_inspection_due_date?->toDateString(),
            'status' => $this->status,
            'blocked_reason' => $reason,
            'lifespan_remaining_days' => $this->lifespanRemainingDays($today),
            'lifespan_percent_used' => $this->lifespanPercentUsed($today),
            'is_assignable' => $reason === null,
        ];
    }
}
