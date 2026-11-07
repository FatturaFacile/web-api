<?php

declare(strict_types=1);

namespace App\Services\ItalianSdi;

use App\Models\Contact;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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

    /**
     * @param  bool  $atIssue  A declaration of intent takes the VAT off the lines only at emission, inside its transaction: a
     *                         draft keeps its rates, so a refused emission (plafond exhausted) leaves nothing to undo.
     */
    public function apply(SalesInvoice $invoice, bool $atIssue = false): void
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
        $hasDeclaration = ! $regime->isForfettario() && $this->applyDeclaration($invoice, $atIssue);

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

        $fund = $this->applyFund($invoice, $net, $highestRate, $regime->isForfettario() || $hasDeclaration);
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
        $invoice->rounding_amount = 0;
        $invoice->receivable_amount = $invoice->amountDue();
    }

    /**
     * A credit note takes back the share of the withholding and of the Enasarco of its invoice that matches the share of
     * the taxable amount it credits: the customer is credited only what it paid, and the withholding receivable and the
     * Enasarco base go down by the same proportion. The pension fund contribution follows the same share: the credit note
     * carries the contribution and its VAT on the credited taxable amount, so its total, its DatiCassaPrevidenziale block
     * and its ledger entry mirror the invoice.
     */
    public function applyToCreditNote(SalesInvoice $creditNote, ?SalesInvoice $original): void
    {
        if (! $this->isItalianSale($creditNote) || ! $creditNote->isCreditNote() || $original === null) {
            return;
        }

        $originalNet = (string) $original->total_net;

        if (! Decimal::isPositive($originalNet)) {
            return;
        }

        $ratio = Decimal::div((string) $creditNote->total_net, $originalNet);
        $ratio = Decimal::compare($ratio, '1') > 0 ? '1' : $ratio;
        $share = static fn (mixed $amount): string => Decimal::round2(Decimal::mul((string) ($amount ?? '0'), $ratio));

        if ($original->withholding_tax_enabled && Decimal::isPositive((string) $original->withholding_tax_amount)) {
            $creditNote->withholding_tax_enabled = true;
            $creditNote->withholding_tax_type = $original->withholding_tax_type;
            $creditNote->withholding_tax_reason = $original->withholding_tax_reason;
            $creditNote->withholding_tax_percent = $original->withholding_tax_percent;
            $creditNote->withholding_tax_amount = $share($original->withholding_tax_amount);
        }

        if ($original->enasarco_enabled && Decimal::isPositive((string) $original->enasarco_amount)) {
            $creditNote->enasarco_enabled = true;
            $creditNote->enasarco_agent_type = $original->enasarco_agent_type;
            $creditNote->enasarco_percent = $original->enasarco_percent;
            $creditNote->enasarco_base = $share($original->enasarco_base);
            $creditNote->enasarco_amount = $share($original->enasarco_amount);
        }

        if ($original->fund_enabled && Decimal::isPositive((string) $original->fund_amount)) {
            $this->applyFundShare($creditNote, $original, $share);
        }
    }

    /**
     * Takes the contribution of the invoice in the share the credit note credits, at the rate and VAT of the invoice, and
     * adds it with its VAT to the totals of the credit note. A contribution already derived is taken out first, so running
     * this twice leaves the same totals.
     *
     * @param  callable(mixed): string  $share
     */
    private function applyFundShare(SalesInvoice $creditNote, SalesInvoice $original, callable $share): void
    {
        $previousFund = $creditNote->fund_enabled ? (string) ($creditNote->fund_amount ?? '0') : '0';
        $previousFundVat = $creditNote->fund_enabled ? (string) ($creditNote->fund_vat_amount ?? '0') : '0';
        $rate = (string) ($original->fund_vat_rate ?? '0');
        $amount = $share($original->fund_amount);
        $vat = $this->percentOf($amount, $rate);

        $creditNote->fund_enabled = true;
        $creditNote->fund_type = $original->fund_type;
        $creditNote->fund_percent = $original->fund_percent;
        $creditNote->fund_vat_rate = $rate;
        $creditNote->fund_subject_to_withholding = (bool) $original->fund_subject_to_withholding;
        $creditNote->fund_amount = $amount;
        $creditNote->fund_vat_amount = $vat;

        $tax = Decimal::add(Decimal::sub((string) $creditNote->total_tax, $previousFundVat), $vat);
        $gross = Decimal::add(Decimal::sub((string) $creditNote->total_gross, Decimal::add($previousFund, $previousFundVat)), Decimal::add($amount, $vat));

        $creditNote->total_tax = Decimal::round2($tax);
        $creditNote->total_gross = Decimal::round2($gross);
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
    private function applyDeclaration(SalesInvoice $invoice, bool $atIssue): bool
    {
        if ($invoice->skip_declaration_of_intent) {
            $invoice->declaration_of_intent_id = null;
            $invoice->vat_nature = $invoice->vat_nature === NaturaIva::N35->value ? null : $invoice->vat_nature;

            return false;
        }

        if (TipoDocumento::ofInvoice($invoice)->isSelfInvoice() || $invoice->split_payment) {
            return false;
        }

        $declaration = $this->declarations->resolveForSale($invoice);

        if ($declaration === null || ! $atIssue) {
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

    /**
     * @param  bool  $isVatFree  Under the flat-rate regime or a declaration of intent the contribution carries no VAT either.
     */
    private function applyFund(SalesInvoice $invoice, string $net, string $highestRate, bool $isVatFree): string
    {
        if (! $invoice->fund_enabled || $invoice->fund_percent === null) {
            $invoice->fund_vat_amount = 0;

            return '0';
        }

        $amount = $this->percentOf($net, (string) $invoice->fund_percent);
        $rate = match (true) {
            $isVatFree => '0',
            $invoice->fund_vat_rate !== null => (string) $invoice->fund_vat_rate,
            default => $highestRate,
        };

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

        $this->serializeCeiling((string) $invoice->tenant_id, (string) $invoice->contact_id, $year);

        $alreadySubject = (string) (SalesInvoice::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $invoice->tenant_id)
            ->where('contact_id', $invoice->contact_id)
            ->where('enasarco_enabled', true)
            ->where('status', 'posted')
            ->whereKeyNot($invoice->id)
            ->whereYear('date', $year)
            ->selectRaw("COALESCE(SUM(CASE WHEN invoice_type = 'credit_note' THEN -enasarco_base ELSE enasarco_base END), 0) AS subject")
            ->value('subject') ?? '0');

        $room = Decimal::sub((string) $parameter->annual_ceiling, $alreadySubject);
        $room = Decimal::isNegative($room) ? '0' : $room;
        $base = Decimal::compare($net, $room) > 0 ? $room : $net;
        $agentRate = $this->percentOf((string) $parameter->rate_percent, (string) $parameter->agent_share_percent);

        $invoice->enasarco_base = Decimal::round2($base);
        $invoice->enasarco_percent = $agentRate;
        $invoice->enasarco_amount = $this->percentOf($base, $agentRate);
    }

    /**
     * The room left below the annual ceiling is read from the documents already issued to the same principal in the
     * year: two emissions reading it at once would both find the same room. A transaction-scoped advisory lock per
     * tenant, principal and year makes the second emission wait until the first has committed, so it reads the base
     * the first one took. Outside a transaction (a draft) the lock is released at once and changes nothing.
     */
    private function serializeCeiling(string $tenantId, string $contactId, int $year): void
    {
        if (DB::getDriverName() !== 'pgsql' || DB::transactionLevel() === 0) {
            return;
        }

        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["enasarco-ceiling:{$tenantId}:{$contactId}:{$year}"]);
    }

    /**
     * Withholding, Enasarco or a pension fund contribution on a credit note stand only as the share of its invoice that
     * applyToCreditNote() derives.
     */
    private function hasUnderivedAdjustment(SalesInvoice $creditNote): bool
    {
        if (! $creditNote->withholding_tax_enabled && ! $creditNote->enasarco_enabled && ! $creditNote->fund_enabled) {
            return false;
        }

        $original = $creditNote->related_invoice_id === null
            ? null
            : SalesInvoice::withoutGlobalScope(TenantScope::class)->where('tenant_id', $creditNote->tenant_id)->whereKey($creditNote->related_invoice_id)->first();

        return ($creditNote->withholding_tax_enabled && ($original === null || ! $original->withholding_tax_enabled))
            || ($creditNote->enasarco_enabled && ($original === null || ! $original->enasarco_enabled))
            || ($creditNote->fund_enabled && ($original === null || ! $original->fund_enabled));
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
        $hasVat = $invoice->items->contains(static fn ($item): bool => ! Decimal::isZero((string) $item->tax_rate))
            || ($invoice->fund_enabled && Decimal::isPositive((string) $invoice->fund_vat_amount));
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
            return $this->hasUnderivedAdjustment($invoice)
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
