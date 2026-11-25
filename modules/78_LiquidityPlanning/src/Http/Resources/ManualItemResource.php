<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\LiquidityPlanning\Models\ForecastManualItem;

/**
 * A manual forecast item.
 *
 * @mixin ForecastManualItem
 */
final class ManualItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'tier' => $this->tier,
            'category' => $this->category,
            'direction' => $this->direction,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'first_date' => $this->first_date->toDateString(),
            'frequency' => $this->frequency,
            'end_date' => $this->end_date?->toDateString(),
            'is_active' => $this->is_active,
            'notes' => $this->notes,
        ];
    }
}
