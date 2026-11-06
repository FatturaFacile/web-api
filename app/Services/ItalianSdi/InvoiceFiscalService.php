<?php

declare(strict_types=1);

namespace App\Services\ItalianSdi;

use App\Models\Contact;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Models\EnasarcoParameter;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesInvoiceItem;
use Modules\Invoicing\Services\DeclarationOfIntentService;
use Modules\SDI\Enums\NaturaIva;
use Modules\SDI\Enums\RegimeFiscale;
use Modules\SDI\Enums\TipoCassa;
use Modules\SDI\Enums\TipoDocumento;

/**
 * The Italian fiscal side of a sales document. `apply()` turns what the document states (fund, withholding, Enasarco, a
 * declaration of intent, the regime of the company) into amounts; `assertIssuable()` refuses what cannot be issued. Both
 * run at emission whatever flow created the document, and documents that use none of this are left untouched.
 */
final class InvoiceFiscalService
{
    /** Attributes a request can state. The amounts are always derived. */
    public const array INPUT_FIELDS = [
        'document_type', 'vat_nature', 'source_passive_invoice_id', 'linked_document_number', 'linked_document_date',
        'declaration_of_intent_id', 'skip_declaration_of_intent', 'withholding_tax_enabled', 'withholding_tax_percent',
        'withholding_tax_type', 'withholding_tax_reason', 'withholding_tax_base_percent', 'stamp_duty_applied',
        'stamp_duty_charged', 'fund_enabled', 'fund_type', 'fund_percent', 'fund_vat_rate', 'fund_subject_to_withholding',
        'enasarco_enabled', 'enasarco_agent_type',
    ];

    public function __construct(private readonly DeclarationOfIntentService $declarations) {}

    public function apply(SalesInvoice $invoice): void
    {
        if (! $this->isItalianSale($invoice) || $invoice->isCreditNote()) {
            return;
        }

        if (TipoDocumento::ofInvoice($invoice)->isSelfInvoice()) {
            $invoice->payment_status = 'paid';
            $invoice->receivable_amount = 0;

            return;
        }

        $invoice->loadMissing('items');
        $tenant = Tenant::query()->find($invoice->tenant_id);
        $regime = RegimeFiscale::ofTenant($tenant);
        $hasDeclaration = ! $regime->isForfettario() && $this->applyDeclaration($invoice);

        if (! $hasDeclaration && ! $regime->isForfettario() && ! $invoice->fund_enabled && ! $invoice->withholding_tax_enabled
            && ! $invoice->enasarco_enabled && ! $invoice->stamp_duty_applied) {
            return;
        }

        $net = '0';
        $tax = '0';
        $vatFree = '0';
        $highestRate = '0';

        foreach ($invoice->items as $item) {
            $net = Decimal::add($net, (string) $item->line_total);
            $tax = Decimal::add($tax, $this->percentOf((string) $item->line_total, (string) $item->tax_rate));
            $vatFree = Decimal::isZero((string) $item->tax_rate) ? Decimal::add($vatFree, (string) $item->line_total) : $vatFree;
            $highestRate = Decimal::compare((string) $item->tax_rate, $highestRate) > 0 ? (string) $item->tax_rate : $highestRate;
        }

        $fund = $this->applyFund($invoice, $net, $highestRate);
        $tax = Decimal::add($tax, (string) $invoice->fund_vat_amount);

        if ($invoice->fund_vat_rate !== null && Decimal::isZero((string) $invoice->fund_vat_rate)) {
            $vatFree = Decimal::add($vatFree, $fund);
        }

        $this->applyWithholding($invoice, $net, $fund);
        $this->applyEnasarco($invoice, $tenant, $net);
        $this->applyStampDuty($invoice, $vatFree, $regime->isForfettario() || $hasDeclaration);

        $gross = Decimal::add(Decimal::add($net, $fund), $tax);

        if ($invoice->stamp_duty_applied && $invoice->stamp_duty_charged) {
            $gross = Decimal::add($gross, (string) $invoice->stamp_duty_amount);
        }

        $invoice->total_tax = Decimal::round2($tax);
        $invoice->total_gross = Decimal::round2($gross);
        $invoice->receivable_amount = $invoice->amountDue();
    }

    /**
     * @throws ValidationException listing every inconsistency of the document
     */
    public function assertIssuable(SalesInvoice $invoice): void
    {
        if (! $this->isItalianSale($invoice)) {
            return;
        }

        $invoice->loadMissing('items');
        $tenant = Tenant::query()->find($invoice->tenant_id);
        $type = TipoDocumento::ofInvoice($invoice);
        $errors = [...$this->documentTypeErrors($invoice, $type), ...$this->regimeErrors($invoice, $tenant, $type), ...$this->amountErrors($invoice)];

        if ($errors !== []) {
            throw ValidationException::withMessages(['invoice' => $errors]);
        }
    }

    private function isItalianSale(SalesInvoice $invoice): bool
    {
        return $invoice->jurisdiction === 'IT' && $invoice->invoice_type !== 'proforma';
    }

    private function percentOf(string $amount, string $percent): string
    {
        return Decimal::round2(Decimal::div(Decimal::mul($amount, $percent), '100'));
    }

    /**
     * Links the sale to the declaration of the customer: its lines lose the VAT and take nature N3.5.
     */
    private function applyDeclaration(SalesInvoice $invoice): bool
    {
        if (TipoDocumento::ofInvoice($invoice)->isSelfInvoice() || $invoice->split_payment) {
            return false;
        }

        $declaration = $this->declarations->resolveForSale($invoice);

        if ($declaration === null) {
            return false;
        }

        $invoice->declaration_of_intent_id = $declaration->id;
        $invoice->vat_nature = NaturaIva::N35->value;

        foreach ($invoice->items as $item) {
            if (! Decimal::isZero((string) $item->tax_rate)) {
                SalesInvoiceItem::withoutGlobalScope(TenantScope::class)->whereKey($item->id)->update(['tax_rate' => 0]);
                $item->tax_rate = 0;
            }
        }

        return true;
    }

    private function applyFund(SalesInvoice $invoice, string $net, string $highestRate): string
    {
        if (! $invoice->fund_enabled || $invoice->fund_percent === null) {
            $invoice->fund_vat_amount = 0;

            return '0';
        }

        $amount = $this->percentOf($net, (string) $invoice->fund_percent);
        $rate = $invoice->fund_vat_rate !== null ? (string) $invoice->fund_vat_rate : $highestRate;

        $invoice->fund_amount = $amount;
        $invoice->fund_vat_rate = $rate;
        $invoice->fund_vat_amount = $this->percentOf($amount, $rate);

        return $amount;
    }

    private function applyWithholding(SalesInvoice $invoice, string $net, string $fund): void
    {
        if (! $invoice->withholding_tax_enabled || $invoice->withholding_tax_percent === null) {
            return;
        }

        $base = $invoice->fund_subject_to_withholding ? Decimal::add($net, $fund) : $net;

        if ($invoice->withholding_tax_base_percent !== null) {
            $base = $this->percentOf($base, (string) $invoice->withholding_tax_base_percent);
        }

        $invoice->withholding_tax_amount = $this->percentOf($base, (string) $invoice->withholding_tax_percent);
    }

    /**
     * The part of the contribution the principal withholds from the agent, on what the commissions of the year leave
     * below the annual ceiling. The figures come from the parameters of the year: without them nothing is computed and
     * the emission is refused.
     */
    private function applyEnasarco(SalesInvoice $invoice, ?Tenant $tenant, string $net): void
    {
        if (! $invoice->enasarco_enabled) {
            return;
        }

        $invoice->enasarco_agent_type ??= $tenant?->settings['enasarco_agent_type'] ?? null;
        $year = CarbonImmutable::parse($invoice->date)->year;
        $parameter = $invoice->enasarco_agent_type !== null
            ? EnasarcoParameter::resolve((string) $invoice->tenant_id, $year, (string) $invoice->enasarco_agent_type)
            : null;

        if ($parameter === null) {
            $invoice->enasarco_percent = null;

            return;
        }

        $alreadySubject = (string) SalesInvoice::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $invoice->tenant_id)
            ->where('contact_id', $invoice->contact_id)
            ->where('enasarco_enabled', true)
            ->where('status', 'posted')
            ->whereKeyNot($invoice->id)
            ->whereYear('date', $year)
            ->sum('enasarco_base');

        $room = Decimal::sub((string) $parameter->annual_ceiling, $alreadySubject);
        $room = Decimal::isNegative($room) ? '0' : $room;
        $base = Decimal::compare($net, $room) > 0 ? $room : $net;
        $agentRate = $this->percentOf((string) $parameter->rate_percent, (string) $parameter->agent_share_percent);

        $invoice->enasarco_base = Decimal::round2($base);
        $invoice->enasarco_percent = $agentRate;
        $invoice->enasarco_amount = $this->percentOf($base, $agentRate);
    }

    private function applyStampDuty(SalesInvoice $invoice, string $vatFree, bool $isAutomatic): void
    {
        $isAboveThreshold = Decimal::compare($vatFree, (string) config('fiscal_it.stamp_duty.threshold')) > 0;

        if ($isAutomatic && $isAboveThreshold) {
            $invoice->stamp_duty_applied = true;
        }

        if ($invoice->stamp_duty_applied && $invoice->stamp_duty_amount === null) {
            $invoice->stamp_duty_amount = (string) config('fiscal_it.stamp_duty.amount');
        }
    }

    /**
     * @return list<string>
     */
    private function documentTypeErrors(SalesInvoice $invoice, TipoDocumento $type): array
    {
        $errors = [];

        if ($invoice->document_type === TipoDocumento::Td04->value && ! $invoice->isCreditNote()) {
            $errors[] = __('sdi.fiscal.td04_needs_credit_note');
        }

        if ($type->requiresLinkedDocument() && $invoice->source_passive_invoice_id === null && $invoice->related_invoice_id === null && empty($invoice->linked_document_number)) {
            $errors[] = __('sdi.fiscal.linked_document_required', ['type' => $type->value]);
        }

        $rule = $type->supplierCountryRule();

        if ($rule !== null) {
            $country = strtoupper((string) Contact::query()->withoutGlobalScopes()->whereKey($invoice->contact_id)->value('country'));

            if (isset($rule['except']) && $country === $rule['except']) {
                $errors[] = __('sdi.fiscal.supplier_cannot_be_from', ['type' => $type->value, 'country' => $rule['except']]);
            }

            if (isset($rule['only']) && $country !== $rule['only']) {
                $errors[] = __('sdi.fiscal.supplier_must_be_from', ['type' => $type->value, 'country' => $rule['only']]);
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function regimeErrors(SalesInvoice $invoice, ?Tenant $tenant, TipoDocumento $type): array
    {
        $hasVat = $invoice->items->contains(static fn ($item): bool => ! Decimal::isZero((string) $item->tax_rate));
        $errors = [];

        if ($invoice->vat_nature !== null && $hasVat) {
            $errors[] = __('sdi.fiscal.nature_with_vat');
        }

        if ($invoice->vat_nature === NaturaIva::N35->value && $invoice->declaration_of_intent_id === null) {
            $errors[] = __('sdi.fiscal.n35_needs_declaration');
        }

        if ($invoice->split_payment && $invoice->reverse_charge) {
            $errors[] = __('sdi.fiscal.split_and_reverse_charge');
        }

        if (! RegimeFiscale::ofTenant($tenant)->isForfettario() || $type->isSelfInvoice()) {
            return $errors;
        }

        if ($hasVat) {
            $errors[] = __('sdi.fiscal.forfettario_no_vat');
        }

        if ($invoice->withholding_tax_enabled) {
            $errors[] = __('sdi.fiscal.forfettario_no_withholding');
        }

        if ($invoice->split_payment || $invoice->reverse_charge) {
            $errors[] = __('sdi.fiscal.forfettario_no_special_vat');
        }

        if ($invoice->declaration_of_intent_id !== null) {
            $errors[] = __('sdi.fiscal.forfettario_no_declaration');
        }

        if ($invoice->vat_nature !== null && $invoice->vat_nature !== NaturaIva::N22->value) {
            $errors[] = __('sdi.fiscal.forfettario_nature');
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function amountErrors(SalesInvoice $invoice): array
    {
        $errors = [];

        if ($invoice->isCreditNote()) {
            return $invoice->fund_enabled || $invoice->withholding_tax_enabled || $invoice->enasarco_enabled
                ? [__('sdi.fiscal.credit_note_adjustments')]
                : [];
        }

        if ($invoice->fund_enabled && (TipoCassa::tryFrom((string) $invoice->fund_type) === null || $invoice->fund_percent === null)) {
            $errors[] = __('sdi.fiscal.fund_incomplete');
        }

        if ($invoice->withholding_tax_enabled && $invoice->withholding_tax_percent === null && $invoice->withholding_tax_amount === null) {
            $errors[] = __('sdi.fiscal.withholding_incomplete');
        }

        if ($invoice->enasarco_enabled && $invoice->enasarco_percent === null) {
            $errors[] = __('sdi.fiscal.enasarco_parameters_missing', ['year' => Date::parse($invoice->date)->year]);
        }

        return $errors;
    }
}
