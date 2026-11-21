<?php

declare(strict_types=1);

namespace App\Http\Resources\Procurement\Rfq;

use App\Domain\Procurement\Rfq\Models\SupplierBidLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SupplierBidLine
 */
final class SupplierBidLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'supplier_bid_id' => (string) $this->supplier_quote_id,
            'rfq_line_id' => (string) $this->rfq_line_id,
            'is_no_bid' => (bool) $this->is_no_bid,
            'quoted_quantity' => (string) $this->quantity,
            'quantity' => (string) $this->quantity,
            'unit_price' => (string) $this->unit_price,
            'discount_percent' => (string) ($this->discount_percent ?? '0.00'),
            'net_unit_price' => (string) $this->net_unit_price,
            'tax_rate' => (string) $this->tax_rate,
            'line_total' => (string) $this->line_total,
            'line_total_net' => (string) $this->line_total_net,
            'lead_time_days' => $this->lead_time_days,
            'promised_delivery_date' => $this->promised_delivery_date?->toDateString(),
            'supplier_notes' => $this->supplier_notes,
            'line_weighted_score' => $this->line_weighted_score !== null ? (string) $this->line_weighted_score : null,
            'is_awarded' => (bool) $this->is_awarded,
        ];
    }
}
