<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FiscalRetail\Models\RetailPromotion;

/**
 * A POS promotion with its validity rules.
 *
 * @mixin RetailPromotion
 */
final class RetailPromotionResource extends JsonResource
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
            'type' => $this->type,
            'value' => $this->value,
            'product_id' => $this->product_id,
            'buy_quantity' => $this->buy_quantity,
            'get_quantity' => $this->get_quantity,
            'min_subtotal' => $this->min_subtotal,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'weekdays' => $this->weekdays ?? [],
            'time_from' => $this->time_from,
            'time_to' => $this->time_to,
            'priority' => $this->priority,
            'is_stackable' => $this->is_stackable,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
