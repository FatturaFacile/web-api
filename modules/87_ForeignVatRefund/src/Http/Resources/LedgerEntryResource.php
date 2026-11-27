<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ForeignVatRefund\Models\ForeignVatLedgerEntry;

/**
 * @mixin ForeignVatLedgerEntry
 */
final class LedgerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_type' => $this->source_type->value,
            'source_id' => $this->source_id,
            'document_number' => $this->document_number,
            'document_date' => $this->document_date->format('Y-m-d'),
            'supplier_name' => $this->supplier_name,
            'supplier_vat_number' => $this->supplier_vat_number,
            'supplier_country_code' => $this->supplier_country_code,
            'expense_category_code' => $this->expense_category_code,
            'net_amount' => (string) $this->net_amount,
            'foreign_vat_rate' => (string) $this->foreign_vat_rate,
            'foreign_vat_amount' => (string) $this->foreign_vat_amount,
            'currency_code' => $this->currency_code,
            'exchange_rate_to_base' => (string) $this->exchange_rate_to_base,
            'base_currency_foreign_vat_amount' => (string) $this->base_currency_foreign_vat_amount,
            'eligible_percentage' => (string) $this->eligible_percentage,
            'recoverable_vat_amount' => (string) $this->recoverable_vat_amount,
            'non_recoverable_vat_amount' => (string) $this->non_recoverable_vat_amount,
            'base_recoverable_vat_amount' => (string) $this->base_recoverable_vat_amount,
            'status' => $this->status->value,
            'vat_refund_claim_id' => $this->vat_refund_claim_id,
            'claim_number' => $this->relationLoaded('claim') ? $this->claim?->claim_number : null,
            'fiscal_year' => $this->fiscal_year,
            'fiscal_quarter' => $this->fiscal_quarter,
            'has_attachment' => $this->attachment_path !== null && $this->attachment_path !== '',
            'journal_entry_id' => $this->journal_entry_id,
            'posting_error' => $this->posting_error,
            'editable' => $this->status->isOpen() || $this->status->value === 'NON_RECOVERABLE_EXPENSED',
        ];
    }
}
