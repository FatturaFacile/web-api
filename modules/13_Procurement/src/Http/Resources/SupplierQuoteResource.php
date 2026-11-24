<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\SupplierQuote;
use Modules\Procurement\Models\SupplierQuoteLine;

/**
 * The quote of a supplier on a request for quotation.
 *
 * @mixin SupplierQuote
 */
final class SupplierQuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'rfq_id' => (string) $this->rfq_id,
            'supplier_id' => (string) $this->supplier_id,
            'quote_reference' => $this->quote_reference,
            'quote_date' => $this->quote_date->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'currency' => $this->currency,
            'lead_time_days' => $this->lead_time_days,
            'status' => $this->status,
            'total_amount' => (string) $this->total_amount,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(static fn (SupplierQuoteLine $line): array => [
                'id' => (string) $line->id,
                'rfq_line_id' => (string) $line->rfq_line_id,
                'quantity' => (string) $line->quantity,
                'unit_price' => (string) $line->unit_price,
                'tax_rate' => (string) $line->tax_rate,
                'line_total' => (string) $line->line_total,
                'lead_time_days' => $line->lead_time_days,
            ])->all()),
        ];
    }
}
