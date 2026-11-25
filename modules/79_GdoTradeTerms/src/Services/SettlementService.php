<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\GdoTradeTerms\Contracts\GdoLedgerPosterInterface;
use Modules\GdoTradeTerms\Domain\Enums\ContractStatus;
use Modules\GdoTradeTerms\Domain\Enums\Jurisdiction;
use Modules\GdoTradeTerms\Domain\Enums\LineStatus;
use Modules\GdoTradeTerms\Domain\Enums\PromotionStatus;
use Modules\GdoTradeTerms\Domain\Enums\SettlementKind;
use Modules\GdoTradeTerms\Domain\Enums\SettlementLineStatus;
use Modules\GdoTradeTerms\Domain\Enums\SettlementStatus;
use Modules\GdoTradeTerms\Domain\Enums\SourceType;
use Modules\GdoTradeTerms\Domain\Enums\TaxTreatment;
use Modules\GdoTradeTerms\Domain\Enums\Treatment;
use Modules\GdoTradeTerms\Events\SettlementSettled;
use Modules\GdoTradeTerms\Exceptions\GdoTradeTermsException;
use Modules\GdoTradeTerms\Models\GdoAccrualLine;
use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Models\GdoSettlement;
use Modules\GdoTradeTerms\Models\GdoSettlementLine;
use Modules\GdoTradeTerms\Support\Money;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;

/**
 * Final settlement of a contract (settlement run). A proposal sets, for every rebate scale and promotion, what was accrued
 * against what was actually earned. Two fiscal treatments follow:
 *  - target rebates (PFA / Umsatzbonus) and flyer price reductions are a reduction of the consideration: settled with a credit
 *    note (Italy: TD04, taxable base reduction under art. 26 DPR 633/72; Switzerland: reduction of the consideration under
 *    art. 41 MWSTG) and the provision of the accrued part is released;
 *  - listing, display, opening, logistics and sell-out contributions pay a service of the retailer: its invoice is reconciled with
 *    the earned amount (within the tolerance of the contract) and the provision is set off against the payable; a larger variance
 *    opens a dispute, resolved by accepting the claim or rejecting it.
 */
final class SettlementService
{
    public function __construct(
        private readonly EarningsService $earnings,
        private readonly GdoLedgerPosterInterface $ledger,
        private readonly LedgerAccountResolver $accounts,
        private readonly InvoicingServiceInterface $invoicing,
    ) {}

    public function propose(GdoContract $contract, string $kind, Carbon $from, Carbon $to, ?string $userId): GdoSettlement
    {
        if (! in_array($contract->status, [ContractStatus::Active, ContractStatus::Closed], true)) {
            throw new GdoTradeTermsException('contract_not_live', 409);
        }

        if ($to->lt($from)) {
            throw new GdoTradeTermsException('period_invalid');
        }

        return DB::transaction(function () use ($contract, $kind, $from, $to, $userId): GdoSettlement {
            if (GdoSettlement::query()->where('contract_id', $contract->id)->whereIn('status', [SettlementStatus::Proposed->value, SettlementStatus::Disputed->value])->lockForUpdate()->exists()) {
                throw new GdoTradeTermsException('open_settlement_exists', 409);
            }

            $contract->loadMissing(['scales.tiers', 'promotions']);
            $lines = [];

            foreach ($contract->scales as $scale) {
                $earned = $this->earnings->scaleEarned($scale, $contract, $to);
                $lines[] = $this->lineData(SourceType::Scale, $scale->id, $scale->code.' · '.$scale->name, Treatment::CreditNote, $earned['earned'], $earned['base_value']);
            }

            foreach ($contract->promotions as $promotion) {
                if ($promotion->status === PromotionStatus::Draft) {
                    continue;
                }

                $earned = $this->earnings->promotionEarned($promotion, $contract, $to);
                $lines[] = $this->lineData(SourceType::Promotion, $promotion->id, $promotion->code.' · '.$promotion->name, $promotion->promotion_type->treatment(), $earned['earned'], $earned['base_value']);
            }

            $lines = array_values(array_filter($lines, static fn (array $line): bool => ! Money::isZero($line['earned_amount']) || ! Money::isZero($line['accrued_amount'])));

            if ($lines === []) {
                throw new GdoTradeTermsException('nothing_to_settle');
            }

            $credit = array_filter($lines, static fn (array $line): bool => $line['treatment'] === Treatment::CreditNote->value);
            $promo = array_filter($lines, static fn (array $line): bool => $line['treatment'] === Treatment::PromoInvoice->value);

            $settlement = GdoSettlement::create([
                'tenant_id' => $contract->tenant_id,
                'contract_id' => $contract->id,
                'code' => $this->nextCode((string) $contract->tenant_id, $to),
                'kind' => SettlementKind::from($kind)->value,
                'period_start' => $from->toDateString(),
                'period_end' => $to->toDateString(),
                'status' => SettlementStatus::Proposed->value,
                'tax_treatment' => ($contract->jurisdiction === Jurisdiction::Ch ? TaxTreatment::ChArt41ConsiderationReduction : TaxTreatment::ItArt26Td04)->value,
                'currency' => $contract->currency,
                'accrued_total' => Money::sum(array_column($lines, 'accrued_amount')),
                'earned_total' => Money::sum(array_column($lines, 'earned_amount')),
                'variance_total' => Money::sum(array_column($lines, 'variance_amount')),
                'credit_note_amount' => Money::sum(array_column($credit, 'earned_amount')),
                'promo_amount' => Money::sum(array_column($promo, 'earned_amount')),
                'created_by' => $userId,
            ]);

            foreach ($lines as $line) {
                GdoSettlementLine::create([...$line, 'tenant_id' => $contract->tenant_id, 'settlement_id' => $settlement->id, 'status' => SettlementLineStatus::Open->value, 'created_by' => $userId]);
            }

            return $settlement->load('lines');
        });
    }

    public function settleCreditNote(GdoSettlement $settlement, string $taxRate, ?string $settledOn, ?string $userId): GdoSettlement
    {
        return DB::transaction(function () use ($settlement, $taxRate, $settledOn, $userId): GdoSettlement {
            $locked = $this->lock($settlement);
            $contract = GdoContract::query()->findOrFail($locked->contract_id);
            $lines = $this->openLines($locked, Treatment::CreditNote);

            if ($lines->isEmpty()) {
                throw new GdoTradeTermsException('nothing_to_settle');
            }

            $amount = Money::round(Money::sum($lines->pluck('earned_amount')), 2);

            if (! Money::isPositive($amount) || $lines->contains(fn (GdoSettlementLine $line): bool => Money::isNegative((string) $line->earned_amount))) {
                throw new GdoTradeTermsException('credit_note_not_positive');
            }

            $date = $settledOn ?? now()->toDateString();
            $italian = $contract->jurisdiction === Jurisdiction::It;
            $items = $lines->filter(fn (GdoSettlementLine $line): bool => Money::isPositive((string) $line->earned_amount))->map(fn (GdoSettlementLine $line): array => [
                'description' => __('gdo_trade_terms.settlement.credit_note_line', ['name' => (string) $line->source_label, 'from' => $locked->period_start->toDateString(), 'to' => $locked->period_end->toDateString()]),
                'quantity' => '1',
                'unit_price' => Money::round((string) $line->earned_amount, 2),
                'tax_rate' => $taxRate,
            ])->values()->all();

            $creditNote = $this->invoicing->createInvoice([
                'tenant_id' => (string) $locked->tenant_id,
                'contact_id' => (string) $contract->contact_id,
                'invoice_type' => 'credit_note',
                'document_type' => $italian ? 'TD04' : null,
                'jurisdiction' => $contract->jurisdiction->value,
                'date' => $date,
                'currency' => $contract->currency,
                'notes' => __($italian ? 'gdo_trade_terms.settlement.credit_note_note_it' : 'gdo_trade_terms.settlement.credit_note_note_ch', ['code' => $contract->code, 'name' => $contract->name]),
                'skip_charge_lines' => true,
                'created_by' => $userId,
            ], $items);

            $released = $this->releaseAccrued($locked, $contract, $lines, $date, $userId);

            $locked->update(['credit_note_id' => (string) $creditNote->id, 'tax_rate' => $taxRate, 'release_journal_entry_id' => $released, 'settled_on' => $date, 'updated_by' => $userId]);

            return $this->refreshStatus($locked, $userId);
        });
    }

    /**
     * Reconciles the promotional invoice of the retailer. Within the tolerance of the contract it is accepted and set off
     * against the provision; beyond it, the settlement is disputed until resolveDispute decides.
     *
     * @param  array{invoice_number: string, invoice_date: string, net: string|int|float, tax?: string|int|float|null, notes?: string|null}  $invoice
     */
    public function reconcilePromoInvoice(GdoSettlement $settlement, array $invoice, ?string $userId): GdoSettlement
    {
        return DB::transaction(function () use ($settlement, $invoice, $userId): GdoSettlement {
            $locked = $this->lock($settlement);
            $contract = GdoContract::query()->findOrFail($locked->contract_id);
            $lines = $this->openLines($locked, Treatment::PromoInvoice, true);

            if ($lines->isEmpty()) {
                throw new GdoTradeTermsException('nothing_to_settle');
            }

            $earned = Money::round(Money::sum($lines->pluck('earned_amount')), 2);
            $net = Money::round((string) $invoice['net'], 2);
            $variance = Money::sub($net, $earned);
            $tolerance = Money::percentOf($earned, (string) ($contract->tolerance_percent ?? config('gdo_trade_terms.tolerance_percent')));

            $locked->update([
                'claim_invoice_number' => $invoice['invoice_number'],
                'claim_invoice_date' => $invoice['invoice_date'],
                'claim_net' => $net,
                'claim_tax' => Money::round((string) ($invoice['tax'] ?? '0'), 2),
                'claim_variance' => $variance,
                'notes' => $invoice['notes'] ?? $locked->notes,
                'updated_by' => $userId,
            ]);

            if (Money::compare(Money::abs($variance), $tolerance) > 0) {
                $locked->update(['dispute_reason' => Money::isPositive($variance) ? 'claim_exceeds_earned' : 'claim_below_earned']);
                GdoSettlementLine::query()->whereIn('id', $lines->pluck('id'))->update(['status' => SettlementLineStatus::Disputed->value]);

                return $this->refreshStatus($locked, $userId);
            }

            $this->setOffPromoInvoice($locked, $contract, $lines, $net, (string) $locked->claim_tax, $userId);

            return $this->refreshStatus($locked, $userId);
        });
    }

    public function resolveDispute(GdoSettlement $settlement, string $resolution, ?string $notes, ?string $userId): GdoSettlement
    {
        return DB::transaction(function () use ($settlement, $resolution, $notes, $userId): GdoSettlement {
            $locked = $this->lock($settlement);

            if ($locked->status !== SettlementStatus::Disputed || $locked->claim_net === null) {
                throw new GdoTradeTermsException('settlement_not_disputed', 409);
            }

            $contract = GdoContract::query()->findOrFail($locked->contract_id);
            $lines = $this->openLines($locked, Treatment::PromoInvoice, true);

            if ($resolution === 'accept_claim') {
                $this->setOffPromoInvoice($locked, $contract, $lines, (string) $locked->claim_net, (string) ($locked->claim_tax ?? '0'), $userId);
                $locked->update(['notes' => $notes ?? $locked->notes, 'dispute_reason' => null]);
            } elseif ($resolution === 'reject') {
                GdoSettlementLine::query()->whereIn('id', $lines->pluck('id'))->update(['status' => SettlementLineStatus::Open->value]);
                $locked->update(['claim_invoice_number' => null, 'claim_invoice_date' => null, 'claim_net' => null, 'claim_tax' => null, 'claim_variance' => null, 'dispute_reason' => null, 'notes' => $notes ?? $locked->notes]);
            } else {
                throw new GdoTradeTermsException('invalid_action');
            }

            return $this->refreshStatus($locked, $userId);
        });
    }

    public function cancel(GdoSettlement $settlement, ?string $userId): GdoSettlement
    {
        return DB::transaction(function () use ($settlement, $userId): GdoSettlement {
            $locked = $this->lock($settlement);

            if (GdoSettlementLine::query()->where('settlement_id', $locked->id)->where('status', SettlementLineStatus::Settled->value)->exists()) {
                throw new GdoTradeTermsException('settlement_has_settled_lines', 409);
            }

            GdoSettlementLine::query()->where('settlement_id', $locked->id)->update(['status' => SettlementLineStatus::Cancelled->value]);
            $locked->update(['status' => SettlementStatus::Cancelled->value, 'updated_by' => $userId]);

            return $locked->refresh()->load('lines');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function lineData(SourceType $type, string $sourceId, string $label, Treatment $treatment, string $cumulative, string $base): array
    {
        $cumulative = Money::round($cumulative, 2);
        $settled = Money::from(GdoSettlementLine::query()->where('source_type', $type->value)->where('source_id', $sourceId)->where('status', SettlementLineStatus::Settled->value)->sum('earned_amount'));
        $earned = Money::sub($cumulative, $settled);
        $accrued = Money::from(GdoAccrualLine::query()->where('source_type', $type->value)->where('source_id', $sourceId)->where('status', LineStatus::Accrued->value)->sum('delta_amount'));

        return [
            'source_type' => $type->value,
            'source_id' => $sourceId,
            'source_label' => $label,
            'treatment' => $treatment->value,
            'base_value' => $base,
            'accrued_amount' => $accrued,
            'earned_amount' => $earned,
            'variance_amount' => Money::sub($earned, $accrued),
            'cumulative_earned' => $cumulative,
        ];
    }

    private function lock(GdoSettlement $settlement): GdoSettlement
    {
        $locked = GdoSettlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();

        if (! in_array($locked->status, [SettlementStatus::Proposed, SettlementStatus::Disputed], true)) {
            throw new GdoTradeTermsException('settlement_closed', 409);
        }

        return $locked;
    }

    /**
     * @return Collection<int, GdoSettlementLine>
     */
    private function openLines(GdoSettlement $settlement, Treatment $treatment, bool $includeDisputed = false): Collection
    {
        $statuses = [SettlementLineStatus::Open->value, ...($includeDisputed ? [SettlementLineStatus::Disputed->value] : [])];

        return GdoSettlementLine::query()->where('settlement_id', $settlement->id)->where('treatment', $treatment->value)->whereIn('status', $statuses)->lockForUpdate()->get();
    }

    /**
     * Marks the accruals of the settled sources as settled and gives the provision back: Dr provision / Cr rebates expense, because
     * the credit note books the whole earned amount on its own.
     *
     * @param  Collection<int, GdoSettlementLine>  $lines
     */
    private function releaseAccrued(GdoSettlement $settlement, GdoContract $contract, Collection $lines, string $date, ?string $userId): ?string
    {
        $accrued = $this->closeAccruals($lines);

        GdoSettlementLine::query()->whereIn('id', $lines->pluck('id'))->update(['status' => SettlementLineStatus::Settled->value]);

        if (Money::isZero($accrued)) {
            return null;
        }

        $debitProvision = Money::isPositive($accrued);
        $amount = Money::abs($accrued);
        $provision = $this->accounts->resolve($contract, 'provision');
        $expense = $this->accounts->resolve($contract, 'expense');

        return $this->ledger->post((string) $settlement->tenant_id, $date, 'release', $settlement->id, 'GDO provision released '.$settlement->code, [
            ['account_id' => $provision, 'debit' => $debitProvision ? $amount : '0', 'credit' => $debitProvision ? '0' : $amount, 'description' => 'Provision for credit notes to be issued released'],
            ['account_id' => $expense, 'debit' => $debitProvision ? '0' : $amount, 'credit' => $debitProvision ? $amount : '0', 'description' => 'Rebates expense carried by the credit note'],
        ], $userId);
    }

    /**
     * Sets the retailer's invoice off against the provision: Dr provision (accrued), Dr/Cr rebates expense (difference), Dr input VAT,
     * Cr payables (net + tax).
     *
     * @param  Collection<int, GdoSettlementLine>  $lines
     */
    private function setOffPromoInvoice(GdoSettlement $settlement, GdoContract $contract, Collection $lines, string $net, string $tax, ?string $userId): void
    {
        $accrued = $this->closeAccruals($lines);
        $date = $settlement->claim_invoice_date?->toDateString() ?? now()->toDateString();
        $difference = Money::sub($net, $accrued);
        $entries = [];

        if (Money::isPositive($accrued)) {
            $entries[] = ['account_id' => $this->accounts->resolve($contract, 'provision'), 'debit' => $accrued, 'credit' => '0', 'description' => 'Provision for promotional contributions set off'];
        }

        if (! Money::isZero($difference)) {
            $extra = Money::isPositive($difference);
            $entries[] = ['account_id' => $this->accounts->resolve($contract, 'expense'), 'debit' => $extra ? $difference : '0', 'credit' => $extra ? '0' : Money::abs($difference), 'description' => 'Variance between the accrual and the invoice of the retailer'];
        }

        if (Money::isPositive($tax)) {
            $entries[] = ['account_id' => $this->accounts->resolve($contract, 'vat'), 'debit' => $tax, 'credit' => '0', 'description' => 'Input VAT on the promotional invoice'];
        }

        $entries[] = ['account_id' => $this->accounts->resolve($contract, 'payable'), 'debit' => '0', 'credit' => Money::add($net, $tax), 'description' => 'Payable to the retailer for promotional services'];

        $journal = Money::isPositive(Money::add($net, $tax)) ? $this->ledger->post((string) $settlement->tenant_id, $date, 'setoff', $settlement->id, 'GDO promotional invoice '.$settlement->claim_invoice_number, $entries, $userId) : null;

        GdoSettlementLine::query()->whereIn('id', $lines->pluck('id'))->update(['status' => SettlementLineStatus::Settled->value]);
        $settlement->update(['set_off_journal_entry_id' => $journal, 'settled_on' => $date, 'dispute_reason' => null]);
    }

    /**
     * Settles the accruals of the sources of the lines and returns what they had accrued.
     *
     * @param  Collection<int, GdoSettlementLine>  $lines
     */
    private function closeAccruals(Collection $lines): string
    {
        $total = '0';

        foreach ($lines as $line) {
            $accruals = GdoAccrualLine::query()->where('source_type', $line->source_type->value)->where('source_id', $line->source_id)->where('status', LineStatus::Accrued->value)->lockForUpdate()->get();
            $total = Money::add($total, Money::sum($accruals->pluck('delta_amount')));
            GdoAccrualLine::query()->whereIn('id', $accruals->pluck('id'))->update(['status' => LineStatus::Settled->value, 'settlement_line_id' => $line->id]);
        }

        return $total;
    }

    private function refreshStatus(GdoSettlement $settlement, ?string $userId): GdoSettlement
    {
        $statuses = GdoSettlementLine::query()->where('settlement_id', $settlement->id)->where('status', '!=', SettlementLineStatus::Cancelled->value)->pluck('status')->map(static fn ($status): string => $status instanceof SettlementLineStatus ? $status->value : (string) $status);

        $status = match (true) {
            $statuses->isNotEmpty() && $statuses->every(static fn (string $value): bool => $value === SettlementLineStatus::Settled->value) => SettlementStatus::Settled,
            $statuses->contains(SettlementLineStatus::Disputed->value) => SettlementStatus::Disputed,
            default => SettlementStatus::Proposed,
        };

        $settlement->update(['status' => $status->value, 'updated_by' => $userId]);
        $settlement->refresh()->load('lines');

        if ($status === SettlementStatus::Settled) {
            event(new SettlementSettled($settlement));
        }

        return $settlement;
    }

    private function nextCode(string $tenantId, Carbon $date): string
    {
        $count = GdoSettlement::withTrashed()->where('tenant_id', $tenantId)->whereYear('period_end', $date->year)->count();

        return sprintf('GDT-%d-%04d', $date->year, $count + 1);
    }
}
