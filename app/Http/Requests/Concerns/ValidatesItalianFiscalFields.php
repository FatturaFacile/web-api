<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Modules\GeneralLedger\Models\EnasarcoParameter;
use Modules\SDI\Enums\CausalePagamento;
use Modules\SDI\Enums\NaturaIva;
use Modules\SDI\Enums\TipoCassa;
use Modules\SDI\Enums\TipoDocumento;
use Modules\SDI\Enums\TipoRitenuta;

/**
 * The Italian fiscal fields a sales document can state. The amounts are never accepted: they are derived at emission.
 */
trait ValidatesItalianFiscalFields
{
    /**
     * @return array<string, mixed>
     */
    protected function italianFiscalRules(?string $tenantId): array
    {
        return [
            'document_type' => ['nullable', Rule::enum(TipoDocumento::class)],
            'vat_nature' => ['nullable', Rule::in(NaturaIva::issuable())],
            'source_passive_invoice_id' => ['nullable', 'uuid', Rule::exists('passive_invoices', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'linked_document_number' => ['nullable', 'string', 'max:20'],
            'linked_document_date' => ['nullable', 'date'],
            'declaration_of_intent_id' => ['nullable', 'uuid', Rule::exists('declarations_of_intent', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'skip_declaration_of_intent' => ['nullable', 'boolean'],
            'withholding_tax_enabled' => ['nullable', 'boolean'],
            'withholding_tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'withholding_tax_type' => ['nullable', Rule::enum(TipoRitenuta::class)],
            'withholding_tax_reason' => ['nullable', Rule::enum(CausalePagamento::class)],
            'withholding_tax_base_percent' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'stamp_duty_applied' => ['nullable', 'boolean'],
            'stamp_duty_charged' => ['nullable', 'boolean'],
            'fund_enabled' => ['nullable', 'boolean'],
            'fund_type' => ['nullable', Rule::enum(TipoCassa::class)],
            'fund_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fund_vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fund_subject_to_withholding' => ['nullable', 'boolean'],
            'enasarco_enabled' => ['nullable', 'boolean'],
            'enasarco_agent_type' => ['nullable', Rule::in(EnasarcoParameter::AGENT_TYPES)],
        ];
    }
}
