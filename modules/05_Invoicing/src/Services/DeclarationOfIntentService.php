<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use App\Models\PassiveInvoice;
use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\DeclarationOfIntent;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * Register of the declarations of intent. A sale consumes the plafond of the customer's declaration when it is issued and
 * is refused above it; a credit note gives the plafond back. Purchases only track what was bought: a received document
 * cannot be refused.
 */
final class DeclarationOfIntentService
{
    /**
     * The declaration that covers a sale: the one it names, else the oldest of the customer valid on its date, preferring
     * one with plafond left. The chosen declaration blocks the sale if it cannot cover it, instead of falling back to VAT.
     */
    public function resolveForSale(SalesInvoice $invoice): ?DeclarationOfIntent
    {
        if ($invoice->skip_declaration_of_intent || $invoice->reverse_charge) {
            return null;
        }

        $query = DeclarationOfIntent::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $invoice->tenant_id)
            ->where('contact_id', $invoice->contact_id)
            ->where('direction', DeclarationOfIntent::RECEIVED);

        if ($invoice->declaration_of_intent_id !== null) {
            return $query->whereKey($invoice->declaration_of_intent_id)->first();
        }

        $date = CarbonImmutable::parse($invoice->date)->toDateString();

        return $query->whereDate('valid_from', '<=', $date)
            ->whereDate('valid_to', '>=', $date)
            ->orderByRaw('(plafond_amount - consumed_amount) > 0 DESC')
            ->orderBy('valid_from')
            ->first();
    }

    /**
     * Consumes the plafond for an issued sale. It runs in the transaction of the emission, under a row lock, so two
     * concurrent sales cannot both take the last part of it.
     *
     * @throws ValidationException when the declaration does not cover the sale
     */
    public function consume(SalesInvoice $invoice): void
    {
        $declaration = DeclarationOfIntent::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $invoice->tenant_id)
            ->whereKey($invoice->declaration_of_intent_id)
            ->lockForUpdate()
            ->first();

        if ($declaration === null) {
            throw ValidationException::withMessages(['declaration_of_intent_id' => __('sdi.fiscal.declaration_not_found')]);
        }

        $date = CarbonImmutable::parse($invoice->date);

        if ($date->lt($declaration->valid_from) || $date->gt($declaration->valid_to)) {
            throw ValidationException::withMessages(['declaration_of_intent_id' => __('sdi.fiscal.declaration_not_valid_on_date', ['protocol' => $declaration->protocol_number])]);
        }

        $amount = Decimal::add((string) $invoice->total_net, (string) ($invoice->fund_amount ?? '0'));

        if (Decimal::compare($amount, $declaration->residualAmount()) > 0) {
            throw ValidationException::withMessages(['declaration_of_intent_id' => __('sdi.fiscal.declaration_over_plafond', [
                'protocol' => $declaration->protocol_number,
                'residual' => $declaration->residualAmount(),
            ])]);
        }

        $declaration->update(['consumed_amount' => Decimal::round2(Decimal::add((string) $declaration->consumed_amount, $amount))]);
    }

    /**
     * Gives back to the declaration of the original invoice what a credit note cancels.
     */
    public function release(SalesInvoice $creditNote, ?SalesInvoice $original): void
    {
        if ($original?->declaration_of_intent_id === null) {
            return;
        }

        $declaration = DeclarationOfIntent::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $original->tenant_id)
            ->whereKey($original->declaration_of_intent_id)
            ->lockForUpdate()
            ->first();

        if ($declaration === null) {
            return;
        }

        $remaining = Decimal::sub((string) $declaration->consumed_amount, $this->releasedAmount($creditNote, $original));

        $declaration->update(['consumed_amount' => Decimal::isNegative($remaining) ? '0.00' : Decimal::round2($remaining)]);
    }

    /**
     * What the credit note gives back, measured as consume() measured the sale: its net plus the matching share of the
     * fund contribution of the original invoice, since a credit note carries no fund of its own.
     */
    private function releasedAmount(SalesInvoice $creditNote, SalesInvoice $original): string
    {
        $creditedNet = (string) $creditNote->total_net;
        $originalNet = (string) $original->total_net;
        $originalFund = (string) ($original->fund_amount ?? '0');

        if (! Decimal::isPositive($originalNet) || ! Decimal::isPositive($originalFund)) {
            return Decimal::add($creditedNet, (string) ($creditNote->fund_amount ?? '0'));
        }

        $share = Decimal::div($creditedNet, $originalNet);
        $share = Decimal::compare($share, '1') > 0 ? '1' : $share;

        return Decimal::add($creditedNet, Decimal::round2(Decimal::mul($originalFund, $share)));
    }

    /**
     * Links a received VAT-free document to the declaration issued to that supplier and tracks what it consumed. A
     * document above the plafond is kept and logged: it already exists, and the problem is the buyer's to correct.
     */
    public function trackPurchase(PassiveInvoice $invoice): void
    {
        if ($invoice->declaration_of_intent_id !== null || $invoice->document_type === 'TD04' || Decimal::isPositive((string) $invoice->total_tax)) {
            return;
        }

        $date = CarbonImmutable::parse($invoice->date)->toDateString();

        $declaration = DeclarationOfIntent::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $invoice->tenant_id)
            ->where('contact_id', $invoice->supplier_id)
            ->where('direction', DeclarationOfIntent::ISSUED)
            ->whereDate('valid_from', '<=', $date)
            ->whereDate('valid_to', '>=', $date)
            ->orderBy('valid_from')
            ->lockForUpdate()
            ->first();

        if ($declaration === null) {
            return;
        }

        $consumed = Decimal::round2(Decimal::add((string) $declaration->consumed_amount, (string) ($invoice->total_net ?? $invoice->total_amount)));
        $declaration->update(['consumed_amount' => $consumed]);
        $invoice->update(['declaration_of_intent_id' => $declaration->id]);

        if (Decimal::compare($consumed, (string) $declaration->plafond_amount) > 0) {
            Log::warning('Purchases above the plafond of a declaration of intent.', ['declaration_id' => $declaration->id, 'passive_invoice_id' => $invoice->id]);
        }
    }

    /**
     * The declarations of a year with what they consumed, totalled per direction.
     *
     * @return array{year: int, totals: array<string, array{plafond: string, consumed: string, residual: string}>, declarations: list<array<string, mixed>>}
     */
    public function annualSummary(string $tenantId, int $year): array
    {
        $declarations = DeclarationOfIntent::withoutGlobalScope(TenantScope::class)
            ->with('contact')
            ->where('tenant_id', $tenantId)
            ->where('year', $year)
            ->orderBy('direction')
            ->orderBy('receipt_date')
            ->get();

        $totals = [];
        $rows = [];

        foreach ($declarations as $declaration) {
            $current = $totals[$declaration->direction] ?? ['plafond' => '0.00', 'consumed' => '0.00', 'residual' => '0.00'];
            $totals[$declaration->direction] = [
                'plafond' => Decimal::round2(Decimal::add($current['plafond'], (string) $declaration->plafond_amount)),
                'consumed' => Decimal::round2(Decimal::add($current['consumed'], (string) $declaration->consumed_amount)),
                'residual' => Decimal::round2(Decimal::add($current['residual'], $declaration->residualAmount())),
            ];
            $rows[] = [
                'id' => (string) $declaration->id,
                'direction' => $declaration->direction,
                'contact_id' => (string) $declaration->contact_id,
                'contact_name' => $declaration->contact?->company_name ?? trim($declaration->contact?->first_name.' '.$declaration->contact?->last_name),
                'protocol_number' => $declaration->protocol_number,
                'plafond_amount' => (string) $declaration->plafond_amount,
                'consumed_amount' => (string) $declaration->consumed_amount,
                'residual_amount' => $declaration->residualAmount(),
                'valid_from' => $declaration->valid_from->toDateString(),
                'valid_to' => $declaration->valid_to->toDateString(),
            ];
        }

        return ['year' => $year, 'totals' => $totals, 'declarations' => $rows];
    }
}
