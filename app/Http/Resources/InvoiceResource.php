<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
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
            'contact' => $this->whenLoaded('contact', fn () => [
                'id' => $this->contact->id,
                'name' => $this->contact->company_name ?: ($this->contact->first_name.' '.$this->contact->last_name),
                'country' => $this->contact->country,
                'vat_number' => $this->contact->vat_number,
                'fiscal_code' => $this->contact->fiscal_code,
                'uid_number' => $this->contact->uid_number,
                'sdi_code' => $this->contact->sdi_code,
                'pec' => $this->contact->pec,
            ]),
            'invoice_type' => $this->invoice_type,
            'invoice_number' => $this->invoice_number,
            'date' => $this->date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'currency' => $this->currency,
            'exchange_rate' => (float) $this->exchange_rate,
            'total_net' => (float) $this->total_net,
            'total_tax' => (float) $this->total_tax,
            'total_gross' => (float) $this->total_gross,
            'rounding_amount' => (float) $this->rounding_amount,
            'payment_status' => $this->payment_status,
            'total_paid' => (float) $this->total_paid,
            'proforma_id' => $this->proforma_id,
            'sales_order_id' => $this->sales_order_id,
            'delivery_note_id' => $this->delivery_note_id,
            'is_deposit' => (bool) $this->is_deposit,
            'jurisdiction' => $this->jurisdiction,
            'split_payment' => (bool) $this->split_payment,
            'reverse_charge' => (bool) $this->reverse_charge,
            'notes' => $this->notes,
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'sdi_metadata' => $this->whenLoaded('sdiMetadata', fn () => [
                'transmission_format' => $this->sdiMetadata->transmission_format,
                'sdi_identifier' => $this->sdiMetadata->sdi_identifier,
                'sdi_status' => $this->sdiMetadata->sdi_status,
                'file_name' => $this->sdiMetadata->file_name,
                'sent_at' => $this->sdiMetadata->sent_at?->toIso8601String(),
                'delivered_at' => $this->sdiMetadata->delivered_at?->toIso8601String(),
            ]),
            'qr_bill_data' => $this->whenLoaded('qrBillData', fn () => [
                'qr_iban' => $this->qrBillData->qr_iban,
                'scor_reference' => $this->qrBillData->scor_reference,
                'creditor_info' => $this->qrBillData->creditor_info,
                'debtor_info' => $this->qrBillData->debtor_info,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
