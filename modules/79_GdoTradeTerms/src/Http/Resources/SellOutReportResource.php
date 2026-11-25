<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GdoTradeTerms\Models\GdoSellOutReport;

/**
 * POS sell-out reported by a retailer for a product and period.
 *
 * @mixin GdoSellOutReport
 */
final class SellOutReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_id' => $this->contract_id,
            'delivery_point_id' => $this->delivery_point_id,
            'delivery_point' => $this->whenLoaded('deliveryPoint', fn (): ?array => $this->deliveryPoint === null ? null : ['id' => $this->deliveryPoint->id, 'code' => $this->deliveryPoint->code, 'name' => $this->deliveryPoint->name]),
            'product_id' => $this->product_id,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'quantity' => $this->quantity,
            'amount' => $this->amount,
            'source' => $this->source,
            'reported_at' => $this->reported_at?->toIso8601String(),
        ];
    }
}
