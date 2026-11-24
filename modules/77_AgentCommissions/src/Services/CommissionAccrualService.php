<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AgentCommissions\Domain\Enums\AccrualBasis;
use Modules\AgentCommissions\Domain\Enums\EntryRole;
use Modules\AgentCommissions\Domain\Enums\EntryStatus;
use Modules\AgentCommissions\Domain\Enums\RateType;
use Modules\AgentCommissions\Domain\Enums\SplitMode;
use Modules\AgentCommissions\Models\AgentProfile;
use Modules\AgentCommissions\Models\CommissionEntry;
use Modules\AgentCommissions\Models\CommissionRule;
use Modules\AgentCommissions\Models\EntryMovement;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesInvoiceItem;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesRepresentative;
use Modules\Payroll\Calculation\Dec;

/**
 * The commission ledger of the agents.
 *
 * `syncInvoice` is idempotent and is the only writer of the ledger. It does two things for an issued invoice:
 *
 *  1. accrual: one entry per invoice line and agent (plus one for the parent agent of a sub-agent), with the rule and
 *     the rate of that moment frozen on the entry, and
 *  2. eligibility: how much of each entry the agent may be paid. With the invoiced basis the whole effective
 *     commission is eligible at issuance. With the collected basis only the share of the amount due that was collected
 *     AND reconciled with the bank is eligible (partial payments release the commission proportionally); a payment that
 *     is still unreconciled releases nothing, and undoing a reconciliation takes the release back.
 *
 * Credit notes never create entries of their own: they reduce the effective commission of the invoice they correct
 * (`credited_amount`), so a commission already settled becomes a negative line (clawback) in the next statement.
 */
final class CommissionAccrualService
{
    private const array CountedInvoiceTypes = ['standard', 'sdi_electronic', 'ch_qr_bill'];

    private const string CreditNoteType = 'credit_note';

    private const array VoidStatuses = ['cancelled', 'void'];

    private const string DraftNumberPrefix = 'DRAFT-';

    public function __construct(
        private readonly RuleResolver $rules,
        private readonly ZoneResolver $zones,
    ) {}

    public function syncInvoice(string $invoiceId): void
    {
        $invoice = SalesInvoice::query()->find($invoiceId);

        if ($invoice === null) {
            return;
        }

        if ($invoice->invoice_type === self::CreditNoteType) {
            if ($invoice->related_invoice_id !== null) {
                $this->syncInvoice((string) $invoice->related_invoice_id);
            }

            return;
        }

        if (! in_array($invoice->invoice_type, self::CountedInvoiceTypes, true)) {
            return;
        }

        $voided = in_array($invoice->status, self::VoidStatuses, true);

        if (! $voided && ! $this->isIssued($invoice)) {
            return;
        }

        DB::transaction(function () use ($invoice, $voided): void {
            if (! $voided) {
                $this->accrue($invoice);
            }

            $this->refreshEligibility($invoice, $voided);
        });
    }

    /**
     * Sync every invoice issued in a period. Returns how many invoices were looked at.
     */
    public function syncPeriod(string $from, string $to): int
    {
        $ids = SalesInvoice::query()
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->whereIn('invoice_type', self::CountedInvoiceTypes)
            ->whereNotIn('status', self::VoidStatuses)
            ->orderBy('date')
            ->pluck('id');

        foreach ($ids as $id) {
            $this->syncInvoice((string) $id);
        }

        return $ids->count();
    }

    /**
     * Re-read the collections of every invoice whose commission is not fully released yet (a payment may have been
     * reconciled or un-reconciled without any event reaching the module).
     */
    public function syncOpen(): int
    {
        $ids = CommissionEntry::query()
            ->whereIn('status', [EntryStatus::PendingCollection->value, EntryStatus::AwaitingReconciliation->value, EntryStatus::PartiallyEligible->value])
            ->distinct()
            ->pluck('invoice_id');

        foreach ($ids as $id) {
            $this->syncInvoice((string) $id);
        }

        return $ids->count();
    }

    private function isIssued(SalesInvoice $invoice): bool
    {
        return ! ($invoice->status === 'draft' && str_starts_with((string) $invoice->invoice_number, self::DraftNumberPrefix));
    }

    private function accrue(SalesInvoice $invoice): void
    {
        $this->rules->flush();
        $this->zones->flush();
        $tenantId = (string) $invoice->tenant_id;
        $representative = $this->representativeOf($invoice);
        $profile = $representative === null ? null : AgentProfile::query()
            ->where('sales_representative_id', $representative->id)
            ->where('is_active', true)
            ->first();
        $on = CarbonImmutable::parse($invoice->date);

        if ($representative === null || $profile === null || ! $this->contractCovers($profile, $on)) {
            return;
        }

        if (strtoupper((string) $invoice->currency) !== TenantAccountingCurrency::of($tenantId)) {
            return; // statements are in the accounting currency, like the legacy commission accrual
        }

        $items = SalesInvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('id')->get();

        if ($items->isEmpty()) {
            return;
        }

        $existing = CommissionEntry::query()->where('invoice_id', $invoice->id)->where('role', EntryRole::Primary->value)->pluck('invoice_item_id')->flip();
        $categories = DB::table('products')->whereIn('id', $items->pluck('product_id')->filter()->all())->pluck('category_id', 'id');
        $zoneIds = $this->zones->zoneIdsFor((string) $invoice->contact_id);
        $fallbackRate = (string) ($profile->default_rate ?? $representative->default_commission_rate ?? '0');
        $running = [];
        $fixed = [];
        $planned = [];

        foreach ($items as $item) {
            $base = Dec::round((string) $item->line_total);

            if ($existing->has($item->id) || Dec::isZero($base)) {
                continue;
            }

            $rule = $this->rules->resolve($profile, (string) $invoice->contact_id, $categories->get($item->product_id), $zoneIds, $on);
            $line = ['item' => $item, 'rule' => $rule, 'base' => $base, 'rate' => null, 'commission' => '0.00'];

            if ($rule !== null && $rule->rate_type === RateType::Fixed->value) {
                $fixed[(string) $rule->id][] = count($planned);
            } elseif ($rule !== null && $rule->tiers->isNotEmpty()) {
                $key = (string) $rule->id;
                $running[$key] ??= $this->cumulativeBase($profile, $rule, $on);
                $tiered = TieredRateCalculator::calculate($base, $running[$key], $rule->tiers->map(static fn ($tier): array => [
                    'from' => (string) $tier->threshold_from,
                    'to' => $tier->threshold_to === null ? null : (string) $tier->threshold_to,
                    'rate' => (string) $tier->rate,
                ])->all());
                $running[$key] = Dec::add($running[$key], $base);
                $line['commission'] = $tiered['commission'];
                $line['rate'] = $tiered['effective_rate'];
            } else {
                $rate = $rule === null ? $fallbackRate : (string) $rule->rate;
                $line['rate'] = Dec::round($rate, 4);
                $line['commission'] = Dec::round(Dec::div(Dec::mul($base, $rate), '100'));
            }

            $planned[] = $line;
        }

        foreach ($fixed as $indexes) {
            $planned = $this->spreadFixedAmount($planned, $indexes);
        }

        foreach ($planned as $line) {
            $this->createEntries($invoice, $profile, $line);
        }
    }

    /**
     * A fixed commission is due once per invoice and rule: it is spread over the lines of the rule in proportion to
     * their base, the last line taking the rounding remainder.
     *
     * @param  list<array<string, mixed>>  $planned
     * @param  list<int>  $indexes
     * @return list<array<string, mixed>>
     */
    private function spreadFixedAmount(array $planned, array $indexes): array
    {
        /** @var CommissionRule $rule */
        $rule = $planned[$indexes[0]]['rule'];
        $amount = Dec::round((string) ($rule->fixed_amount ?? '0'));
        $total = Dec::sum(array_map(static fn (int $index): string => (string) $planned[$index]['base'], $indexes));
        $allocated = '0';
        $last = count($indexes) - 1;

        foreach ($indexes as $position => $index) {
            $share = $position === $last
                ? Dec::sub($amount, $allocated)
                : Dec::round(Dec::div(Dec::mul($amount, (string) $planned[$index]['base']), $total));
            $allocated = Dec::add($allocated, $share);
            $planned[$index]['commission'] = Dec::round($share);
            $planned[$index]['rate'] = null;
        }

        return $planned;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function createEntries(SalesInvoice $invoice, AgentProfile $profile, array $line): void
    {
        /** @var SalesInvoiceItem $item */
        $item = $line['item'];
        /** @var CommissionRule|null $rule */
        $rule = $line['rule'];
        $basis = $rule === null ? $profile->accrual_basis : ($rule->accrual_basis ?? $profile->accrual_basis);
        $commission = (string) $line['commission'];
        $parent = $this->parentOf($profile);
        $override = '0.00';
        $overrideRate = null;

        if ($parent !== null) {
            $value = (string) ($profile->parent_split_value ?? '0');
            $overrideRate = Dec::round($value, 4);

            if ($profile->parent_split_mode === SplitMode::ShareOfCommission->value) {
                $override = Dec::round(Dec::div(Dec::mul($commission, $value), '100'));
                $commission = Dec::sub($commission, $override);
            } else {
                $override = Dec::round(Dec::div(Dec::mul((string) $line['base'], $value), '100'));
            }
        }

        $primary = CommissionEntry::query()->create($this->entryAttributes($invoice, $profile, $item, EntryRole::Primary, $basis, $line['base'], $line['rate'], $commission, $rule?->id));

        if ($parent !== null && ! Dec::isZero($override)) {
            CommissionEntry::query()->create($this->entryAttributes($invoice, $parent, $item, EntryRole::ParentOverride, $basis, $line['base'], $overrideRate, $override, $rule?->id, (string) $primary->id));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function entryAttributes(SalesInvoice $invoice, AgentProfile $profile, SalesInvoiceItem $item, EntryRole $role, string $basis, string $base, ?string $rate, string $commission, mixed $ruleId, ?string $sourceEntryId = null): array
    {
        return [
            'tenant_id' => $invoice->tenant_id,
            'agent_profile_id' => $profile->id,
            'role' => $role->value,
            'invoice_id' => $invoice->id,
            'invoice_item_id' => $item->id,
            'source_entry_id' => $sourceEntryId,
            'rule_id' => $ruleId,
            'accrual_basis' => $basis,
            'currency' => strtoupper((string) $invoice->currency),
            'accrued_on' => CarbonImmutable::parse($invoice->date)->toDateString(),
            'base_amount' => $base,
            'rate' => $rate,
            'commission_amount' => $commission,
            'status' => $basis === AccrualBasis::Invoiced->value ? EntryStatus::Eligible->value : EntryStatus::PendingCollection->value,
        ];
    }

    private function refreshEligibility(SalesInvoice $invoice, bool $voided): void
    {
        $entries = CommissionEntry::query()->where('invoice_id', $invoice->id)->lockForUpdate()->get();

        if ($entries->isEmpty()) {
            return;
        }

        $gross = Dec::round(Dec::max('0', (string) $invoice->total_gross));
        $credited = $voided ? $gross : $this->creditedGross($invoice);
        $creditRatio = Dec::isPositive($gross) ? Dec::min('1', Dec::div($credited, $gross)) : '0';
        $due = Dec::max('0', Dec::sub($gross, $credited));
        $collected = $this->paid($invoice, false);
        $reconciled = $this->paid($invoice, true);
        $collectedRatio = Dec::isPositive($due) ? Dec::max('0', Dec::min('1', Dec::div($collected, $due))) : '0';
        $reconciledRatio = Dec::isPositive($due) ? Dec::max('0', Dec::min('1', Dec::div($reconciled, $due))) : '0';
        $today = CarbonImmutable::today()->toDateString();

        foreach ($entries as $entry) {
            $commission = (string) $entry->commission_amount;
            $creditedAmount = Dec::isPositive($creditRatio) ? Dec::round(Dec::mul($commission, $creditRatio)) : '0.00';
            $effective = Dec::sub($commission, $creditedAmount);
            $collectedBasis = $entry->accrual_basis === AccrualBasis::Collected->value;
            $target = match (true) {
                ! $collectedBasis => $effective,
                Dec::cmp($reconciledRatio, '1') >= 0 => $effective,
                default => Dec::round(Dec::mul($effective, $reconciledRatio)),
            };
            $delta = Dec::sub($target, (string) $entry->eligible_amount);

            if (! Dec::isZero($delta)) {
                $first = Dec::isZero((string) $entry->eligible_amount) && ! $collectedBasis && ! Dec::isNegative($delta);
                EntryMovement::query()->create([
                    'tenant_id' => $entry->tenant_id,
                    'entry_id' => $entry->id,
                    'kind' => $this->movementKind($collectedBasis, $delta, $voided),
                    'amount' => Dec::round($delta),
                    'occurred_on' => $first ? CarbonImmutable::parse($entry->accrued_on)->toDateString() : $today,
                    'note' => Str::limit('invoice '.$invoice->invoice_number, 250, ''),
                ]);
            }

            $entry->forceFill([
                'credited_amount' => $creditedAmount,
                'eligible_amount' => Dec::round($target),
                'collected_ratio' => Dec::round($collectedRatio, 6),
                'reconciled_ratio' => Dec::round($reconciledRatio, 6),
                'status' => $this->statusOf($entry, $effective, $target, $collectedRatio, $reconciledRatio)->value,
            ])->save();
        }
    }

    private function movementKind(bool $collectedBasis, string $delta, bool $voided): string
    {
        if ($voided || (! $collectedBasis && Dec::isNegative($delta))) {
            return 'credit_note';
        }

        return match (true) {
            ! $collectedBasis => 'accrual',
            Dec::isNegative($delta) => 'reversal',
            default => 'release',
        };
    }

    public function statusOf(CommissionEntry $entry, string $effective, string $eligible, string $collectedRatio, string $reconciledRatio): EntryStatus
    {
        if (Dec::isZero($effective) && ! Dec::isZero((string) $entry->commission_amount)) {
            return EntryStatus::Cancelled;
        }

        if (Dec::cmp($eligible, $effective) === 0) {
            return Dec::cmp((string) $entry->settled_amount, $eligible) === 0 && ! Dec::isZero($eligible) ? EntryStatus::Settled : EntryStatus::Eligible;
        }

        if (! Dec::isPositive($reconciledRatio)) {
            return Dec::isPositive($collectedRatio) ? EntryStatus::AwaitingReconciliation : EntryStatus::PendingCollection;
        }

        return EntryStatus::PartiallyEligible;
    }

    /**
     * Gross amount of the issued credit notes that correct an invoice.
     */
    private function creditedGross(SalesInvoice $invoice): string
    {
        $notes = SalesInvoice::query()
            ->where('related_invoice_id', $invoice->id)
            ->where('invoice_type', self::CreditNoteType)
            ->whereNotIn('status', self::VoidStatuses)
            ->get(['status', 'invoice_number', 'total_gross']);

        return Dec::round(Dec::sum($notes
            ->filter(fn (SalesInvoice $note): bool => $this->isIssued($note))
            ->map(static fn (SalesInvoice $note): string => Dec::max((string) $note->total_gross, Dec::neg((string) $note->total_gross)))));
    }

    /**
     * Money received on an invoice; with `$reconciledOnly` only the payments whose bank statement line is reconciled.
     */
    private function paid(SalesInvoice $invoice, bool $reconciledOnly): string
    {
        $query = DB::table('payments')->where('payments.invoice_id', $invoice->id)->whereNull('payments.deleted_at');

        if ($reconciledOnly) {
            $query->join('bank_statement_lines as bsl', 'bsl.id', '=', 'payments.bank_statement_line_id')->where('bsl.reconciled', true);
        }

        return Dec::round(Dec::of(number_format((float) $query->sum('payments.amount'), 4, '.', '')));
    }

    private function cumulativeBase(AgentProfile $profile, CommissionRule $rule, CarbonImmutable $on): string
    {
        $sum = CommissionEntry::query()
            ->where('agent_profile_id', $profile->id)
            ->where('role', EntryRole::Primary->value)
            ->where('rule_id', $rule->id)
            ->whereDate('accrued_on', '>=', $on->startOfYear()->toDateString())
            ->whereDate('accrued_on', '<=', $on->endOfYear()->toDateString())
            ->sum('base_amount');

        return Dec::round(Dec::of(number_format((float) $sum, 4, '.', '')));
    }

    private function parentOf(AgentProfile $profile): ?AgentProfile
    {
        if ($profile->parent_agent_profile_id === null) {
            return null;
        }

        return AgentProfile::query()->whereKey($profile->parent_agent_profile_id)->where('is_active', true)->first();
    }

    private function contractCovers(AgentProfile $profile, CarbonImmutable $on): bool
    {
        return ($profile->contract_start === null || $on->gte($profile->contract_start->startOfDay()))
            && ($profile->contract_end === null || $on->lte($profile->contract_end->endOfDay()));
    }

    /**
     * The sales representative of an invoice: the one on the invoice, or on its order. `sales_rep_id` is not
     * constrained to a table, so it is read as a representative and then as the user of one.
     */
    private function representativeOf(SalesInvoice $invoice): ?SalesRepresentative
    {
        $declared = $invoice->sales_rep_id;

        if ($declared === null && $invoice->sales_order_id !== null) {
            $declared = SalesOrder::query()->whereKey($invoice->sales_order_id)->value('sales_rep_id');
        }

        if (! is_string($declared) || ! Str::isUuid($declared)) {
            return null;
        }

        return SalesRepresentative::query()
            ->where(static fn ($query) => $query->whereKey($declared)->orWhere('user_id', $declared))
            ->first();
    }

    /**
     * @return Collection<int, CommissionEntry>
     */
    public function entriesOfInvoice(string $invoiceId): Collection
    {
        return CommissionEntry::query()->where('invoice_id', $invoiceId)->get();
    }
}
