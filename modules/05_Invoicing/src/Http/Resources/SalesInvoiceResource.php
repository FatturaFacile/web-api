<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * @mixin SalesInvoice
 */
final class SalesInvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'contact_id' => $this->contact_id,
            'invoice_number' => $this->invoice_number,
            'invoice_type' => $this->invoice_type,
            'status' => $this->status,
            'date' => $this->date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'currency' => $this->currency,
            'exchange_rate' => (float) $this->exchange_rate,
            'subtotal' => (float) $this->subtotal,
            'discount_amount' => (float) $this->discount_amount,
            'total_net' => (float) $this->total_net,
            'total_tax' => (float) $this->total_tax,
            'total_gross' => (float) $this->total_gross,
            'receivable_amount' => (float) $this->receivable_amount,
            'total_paid' => (float) $this->total_paid,
            'outstanding_balance' => (float) $this->outstanding_balance,
            'payment_status' => $this->payment_status,
            'jurisdiction' => $this->jurisdiction,
            'split_payment' => (bool) $this->split_payment,
            'reverse_charge' => (bool) $this->reverse_charge,
            'sales_order_id' => $this->sales_order_id,
            'delivery_note_id' => $this->delivery_note_id,
            'related_invoice_id' => $this->related_invoice_id,
            'project_id' => $this->project_id,
            'project_milestone_id' => $this->project_milestone_id,
            'posted_at' => $this->posted_at?->toISOString(),
            'posted_by' => $this->posted_by,
            'items' => $this->whenLoaded('items'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
