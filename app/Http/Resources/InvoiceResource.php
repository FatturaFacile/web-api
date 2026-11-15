<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Invoice;
use App\Support\Money;
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
            'vat_payability' => $this->vat_payability,
            // Italian fiscal figures (issue #286): cent-exact decimal strings.
            'total_due' => $this->decimal($this->total_due),
            'fund' => $this->fund_enabled ? [
                'type' => $this->fund_type,
                'percent' => $this->decimal($this->fund_percent),
                'amount' => $this->decimal($this->fund_amount),
                'vat_rate' => $this->decimal($this->fund_vat_rate),
                'natura' => $this->fund_natura,
                'withholding_applies' => (bool) $this->fund_withholding_applies,
            ] : null,
            'withholding' => $this->withholding_tax_enabled ? [
                'type' => $this->withholding_tax_type,
                'reason' => $this->withholding_tax_reason,
                'rate' => $this->decimal($this->withholding_tax_percent),
                'taxable_percent' => $this->decimal($this->withholding_tax_taxable_percent),
                'includes_fund' => (bool) $this->withholding_tax_includes_fund,
                'base' => $this->decimal($this->withholding_tax_base),
                'amount' => $this->decimal($this->withholding_tax_amount),
            ] : null,
            'enasarco' => $this->enasarco_amount !== null ? [
                'rate' => $this->decimal($this->enasarco_percent),
                'commissions' => $this->decimal($this->enasarco_commissions),
                'contributory_base' => $this->decimal($this->enasarco_taxable),
                'amount' => $this->decimal($this->enasarco_amount),
            ] : null,
            'stamp_duty_applied' => (bool) $this->stamp_duty_applied,
            'stamp_duty_amount' => $this->decimal($this->stamp_duty_amount),
            'stamp_duty_charged_to_customer' => (bool) $this->stamp_duty_charged_to_customer,
            'vat_summary' => $this->vat_summary,
            'legal_notices' => $this->legal_notices ?? [],
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

    private function decimal(mixed $value): ?string
    {
        return $value === null ? null : Money::fromCents(Money::toCents((string) $value));
    }
}
