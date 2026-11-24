<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use Modules\SDI\Enums\TipoDocumento;

/**
 * The Italian fiscal fields of a sales document, shared by the two invoice resources.
 */
trait ExposesItalianFiscalFields
{
    /**
     * @return array<string, mixed>
     */
    protected function italianFiscalFields(): array
    {
        return [
            'document_type' => TipoDocumento::ofInvoice($this->resource)->value,
            'vat_nature' => $this->vat_nature,
            'amount_due' => (float) $this->amountDue(),
            'source_passive_invoice_id' => $this->source_passive_invoice_id,
            'linked_document_number' => $this->linked_document_number,
            'linked_document_date' => $this->linked_document_date?->toDateString(),
            'declaration_of_intent_id' => $this->declaration_of_intent_id,
            'withholding_tax_enabled' => (bool) $this->withholding_tax_enabled,
            'withholding_tax_type' => $this->withholding_tax_type,
            'withholding_tax_reason' => $this->withholding_tax_reason,
            'withholding_tax_percent' => $this->withholding_tax_percent !== null ? (float) $this->withholding_tax_percent : null,
            'withholding_tax_amount' => $this->withholding_tax_amount !== null ? (float) $this->withholding_tax_amount : null,
            'stamp_duty_applied' => (bool) $this->stamp_duty_applied,
            'stamp_duty_charged' => (bool) $this->stamp_duty_charged,
            'stamp_duty_amount' => $this->stamp_duty_amount !== null ? (float) $this->stamp_duty_amount : null,
            'fund_enabled' => (bool) $this->fund_enabled,
            'fund_type' => $this->fund_type,
            'fund_percent' => $this->fund_percent !== null ? (float) $this->fund_percent : null,
            'fund_amount' => $this->fund_amount !== null ? (float) $this->fund_amount : null,
            'fund_vat_rate' => $this->fund_vat_rate !== null ? (float) $this->fund_vat_rate : null,
            'enasarco_enabled' => (bool) $this->enasarco_enabled,
            'enasarco_agent_type' => $this->enasarco_agent_type,
            'enasarco_base' => $this->enasarco_base !== null ? (float) $this->enasarco_base : null,
            'enasarco_percent' => $this->enasarco_percent !== null ? (float) $this->enasarco_percent : null,
            'enasarco_amount' => (float) $this->enasarco_amount,
        ];
    }
}
