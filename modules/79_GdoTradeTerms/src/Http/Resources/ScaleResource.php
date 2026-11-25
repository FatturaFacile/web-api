<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GdoTradeTerms\Models\GdoScale;
use Modules\GdoTradeTerms\Models\GdoScaleTier;

/**
 * A tiered year-end rebate scale with its brackets.
 *
 * @mixin GdoScale
 */
final class ScaleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_id' => $this->contract_id,
            'code' => $this->code,
            'name' => $this->name,
            'tier_mode' => $this->tier_mode->value,
            'basis' => $this->basis->value,
            'volume_mode' => $this->volume_mode->value,
            'baseline_source' => $this->baseline_source->value,
            'baseline_value' => $this->baseline_value,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'category_ids' => $this->category_ids ?? [],
            'brand_ids' => $this->brand_ids ?? [],
            'product_ids' => $this->product_ids ?? [],
            'delivery_point_ids' => $this->delivery_point_ids ?? [],
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'tiers' => $this->whenLoaded('tiers', fn (): array => $this->tiers->map(static fn (GdoScaleTier $tier): array => [
                'id' => $tier->id,
                'position' => $tier->position,
                'threshold' => $tier->threshold,
                'rate_percent' => $tier->rate_percent,
                'flat_amount' => $tier->flat_amount,
                'label' => $tier->label,
            ])->values()->all()),
        ];
    }
}
