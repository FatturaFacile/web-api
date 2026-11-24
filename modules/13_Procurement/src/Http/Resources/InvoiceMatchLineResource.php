<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\InvoiceMatchLine;

/**
 * @mixin InvoiceMatchLine
 */
final class InvoiceMatchLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_number' => $this->line_number,
            'description' => $this->description,
            'purchase_order_line_id' => $this->purchase_order_line_id,
            'passive_invoice_line_id' => $this->passive_invoice_line_id,
            'status' => $this->status->value,
            'is_blocking' => $this->status->isBlocking(),
            'receipt_required' => $this->receipt_required,
            'ordered_quantity' => (float) $this->ordered_quantity,
            'received_quantity' => (float) $this->received_quantity,
            'previously_invoiced_quantity' => (float) $this->previously_invoiced_quantity,
            'invoiced_quantity' => (float) $this->invoiced_quantity,
            'ordered_unit_price' => (float) $this->ordered_unit_price,
            'invoiced_unit_price' => (float) $this->invoiced_unit_price,
            'quantity_variance' => (float) $this->quantity_variance,
            'price_variance' => (float) $this->price_variance,
            'price_variance_percent' => (float) $this->price_variance_percent,
            'amount_variance' => (float) $this->amount_variance,
            'allowed_quantity_variance' => (float) $this->allowed_quantity_variance,
            'allowed_amount_variance' => (float) $this->allowed_amount_variance,
            'gr_ir_amount' => (float) $this->gr_ir_amount,
            'tolerance_rule_id' => $this->tolerance_rule_id,
            'reasons' => $this->reasons ?? [],
        ];
    }
}
