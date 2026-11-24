<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\AgentCommissions\Domain\Enums\AccrualBasis;
use Modules\AgentCommissions\Domain\Enums\EntryRole;
use Modules\AgentCommissions\Domain\Enums\EntryStatus;
use Modules\AgentCommissions\Domain\Enums\PeriodType;
use Modules\AgentCommissions\Domain\Enums\SettlementChannel;
use Modules\AgentCommissions\Domain\Enums\StatementStatus;
use Modules\AgentCommissions\Exceptions\AgentCommissionException;
use Modules\AgentCommissions\Models\AgentProfile;
use Modules\AgentCommissions\Models\AgentYearBalance;
use Modules\AgentCommissions\Models\CommissionEntry;
use Modules\AgentCommissions\Models\EntryMovement;
use Modules\AgentCommissions\Models\Statement;
use Modules\AgentCommissions\Models\StatementLine;
use Modules\Payroll\Calculation\Dec;

/**
 * Commission statements (estratto conto provvigionale) and their liquidation lifecycle:
 * draft -> approved -> invoiced (self-billing / parcella prepared) -> paid, or void while it is draft or approved.
 *
 * A statement sweeps, for one agent, every ledger entry whose eligible amount (as of the end of the period) is not
 * settled yet. Because the eligible amount of a cash-basis commission only grows with bank-reconciled collections, a
 * commission that waits for the bank can never reach a statement; the approval and the payment check it once more, so
 * a reconciliation undone after the statement was drawn blocks the payout until the statement is voided and redone.
 */
final class StatementService
{
    public function __construct(private readonly FiscalEngine $engine) {}

    /**
     * What a statement of the period would contain, without saving anything.
     *
     * @return array<string, mixed>
     */
    public function preview(string $tenantId, AgentProfile $profile, string $from, string $to): array
    {
        return $this->compute($tenantId, $profile, $from, $to, null)['summary'];
    }

    public function generate(string $tenantId, AgentProfile $profile, string $from, string $to, PeriodType $type, ?string $actorId): Statement
    {
        return DB::transaction(function () use ($tenantId, $profile, $from, $to, $type, $actorId): Statement {
            $year = (int) CarbonImmutable::parse($to)->format('Y');
            $balance = $this->balanceFor($tenantId, $profile, $year);
            $computed = $this->compute($tenantId, $profile, $from, $to, $balance);

            if ($computed['lines'] === []) {
                throw new AgentCommissionException('No commission is due in this period.', 'nothing_to_settle');
            }

            $summary = $computed['summary'];
            $fiscal = $summary['fiscal'];
            $statement = Statement::query()->create([
                'tenant_id' => $tenantId,
                'agent_profile_id' => $profile->id,
                'number' => $this->nextNumber('EC', 'number', $year),
                'period_type' => $type->value,
                'period_start' => $from,
                'period_end' => $to,
                'status' => StatementStatus::Draft->value,
                'currency' => $summary['currency'],
                'base_total' => $summary['base_total'],
                'own_commission_total' => $summary['own_commission_total'],
                'override_commission_total' => $summary['override_commission_total'],
                'clawback_total' => $summary['clawback_total'],
                'gross_commission' => $summary['gross_commission'],
                'enasarco_base' => $fiscal['enasarco_base'],
                'enasarco_agent' => $fiscal['enasarco_agent'],
                'enasarco_company' => $fiscal['enasarco_company'],
                'firr_accrual' => $fiscal['firr'],
                'withholding_base' => $fiscal['withholding_base'],
                'withholding_amount' => $fiscal['withholding'],
                'vat_amount' => $fiscal['vat'],
                'net_payable' => $summary['net_payable'],
                'blocked_amount' => $summary['blocked_amount'],
                'massimale_reached' => $fiscal['massimale_reached'],
                'fiscal_snapshot' => ['fiscal' => $fiscal, 'balance_before' => $this->balanceArray($balance), 'actor' => $actorId],
            ]);

            foreach ($computed['lines'] as $line) {
                StatementLine::query()->create([
                    'tenant_id' => $tenantId,
                    'statement_id' => $statement->id,
                    'entry_id' => $line['entry_id'],
                    'invoice_id' => $line['invoice_id'],
                    'invoice_number' => $line['invoice_number'],
                    'invoice_date' => $line['invoice_date'],
                    'customer_name' => $line['customer_name'],
                    'role' => $line['role'],
                    'accrual_basis' => $line['accrual_basis'],
                    'base_amount' => $line['base_amount'],
                    'rate' => $line['rate'],
                    'commission_amount' => $line['commission_amount'],
                ]);

                $entry = CommissionEntry::query()->lockForUpdate()->findOrFail($line['entry_id']);
                $settled = Dec::round(Dec::add((string) $entry->settled_amount, $line['commission_amount']));
                $entry->forceFill([
                    'settled_amount' => $settled,
                    'status' => $this->settledStatus($entry, $settled),
                ])->save();
            }

            $balance->forceFill([
                'commission_ytd' => $fiscal['commission_ytd_after'],
                'enasarco_base_ytd' => $fiscal['enasarco_base_ytd_after'],
                'enasarco_agent_ytd' => $fiscal['enasarco_agent_ytd_after'],
                'enasarco_company_ytd' => $fiscal['enasarco_company_ytd_after'],
                'firr_ytd' => $fiscal['firr_ytd_after'],
                'massimale' => $fiscal['massimale'],
                'minimale' => $fiscal['minimale'],
            ])->save();

            return $statement->load('lines');
        });
    }

    public function approve(Statement $statement, ?string $actorId): Statement
    {
        return DB::transaction(function () use ($statement, $actorId): Statement {
            $locked = $this->lock($statement);
            $this->assertStatus($locked, [StatementStatus::Draft], 'only_draft_can_be_approved');
            $this->assertPayoutNotBlocked($locked);
            $locked->forceFill([
                'status' => StatementStatus::Approved->value,
                'approved_by' => $actorId,
                'approved_at' => CarbonImmutable::now(),
            ])->save();

            return $locked;
        });
    }

    /**
     * Cancel a statement that was not invoiced or paid: its lines go back to the ledger and its effect on the
     * Enasarco / FIRR balance of the year is taken back.
     */
    public function void(Statement $statement): Statement
    {
        return DB::transaction(function () use ($statement): Statement {
            $locked = $this->lock($statement);
            $this->assertStatus($locked, [StatementStatus::Draft, StatementStatus::Approved], 'cannot_void');

            foreach ($locked->lines()->get() as $line) {
                $entry = CommissionEntry::query()->lockForUpdate()->find($line->entry_id);

                if ($entry === null) {
                    continue;
                }

                $settled = Dec::round(Dec::sub((string) $entry->settled_amount, (string) $line->commission_amount));
                $entry->forceFill([
                    'settled_amount' => $settled,
                    'status' => $entry->status === EntryStatus::Settled->value ? EntryStatus::Eligible->value : $entry->status,
                ])->save();
            }

            $snapshot = $locked->fiscal_snapshot ?? [];
            $before = $snapshot['balance_before'] ?? null;

            if (is_array($before)) {
                AgentYearBalance::query()
                    ->where('agent_profile_id', $locked->agent_profile_id)
                    ->where('year', (int) $locked->period_end->format('Y'))
                    ->first()?->forceFill($before)->save();
            }

            $locked->forceFill(['status' => StatementStatus::Void->value])->save();

            return $locked;
        });
    }

    /**
     * Prepare the commission invoice of the agent (parcella) or the self-billing document (autofattura provvigionale):
     * the figures and the numbering, ready for the invoicing flow. No fiscal document is transmitted from here.
     */
    public function prepareSelfBilling(Statement $statement, ?string $date = null): Statement
    {
        return DB::transaction(function () use ($statement, $date): Statement {
            $locked = $this->lock($statement);
            $this->assertStatus($locked, [StatementStatus::Approved], 'only_approved_can_be_invoiced');
            $profile = AgentProfile::query()->with('representative')->findOrFail($locked->agent_profile_id);

            if ($profile->settlement_channel === SettlementChannel::Salary->value) {
                throw new AgentCommissionException('This agent is settled on salary: there is no invoice to prepare.', 'settled_on_salary');
            }

            $issued = CarbonImmutable::parse($date ?? 'today');
            $fiscal = $locked->fiscal_snapshot['fiscal'] ?? [];
            $number = $this->nextNumber('AF', 'self_billing_number', (int) $issued->format('Y'));
            $locked->forceFill([
                'status' => StatementStatus::Invoiced->value,
                'self_billing_number' => $number,
                'self_billing_date' => $issued->toDateString(),
                'self_billing_payload' => [
                    'document_kind' => 'agent_commission_invoice',
                    'number' => $number,
                    'date' => $issued->toDateString(),
                    'currency' => $locked->currency,
                    'statement_number' => $locked->number,
                    'period' => ['from' => $locked->period_start->toDateString(), 'to' => $locked->period_end->toDateString()],
                    'agent' => [
                        'name' => $profile->representative?->name,
                        'email' => $profile->representative?->email,
                        'fiscal_regime' => $profile->fiscal_regime,
                        'enasarco_registration_number' => $profile->enasarco_registration_number,
                    ],
                    'taxable_amount' => (string) $locked->gross_commission,
                    'vat_rate' => $fiscal['vat_rate'] ?? null,
                    'vat_amount' => (string) $locked->vat_amount,
                    'document_total' => Dec::round(Dec::add((string) $locked->gross_commission, (string) $locked->vat_amount)),
                    'enasarco_agent' => (string) $locked->enasarco_agent,
                    'withholding_base' => (string) $locked->withholding_base,
                    'withholding_rate' => $fiscal['withholding_rate'] ?? null,
                    'withholding_amount' => (string) $locked->withholding_amount,
                    'net_payable' => (string) $locked->net_payable,
                    'lines' => $locked->lines()->get()->map(static fn (StatementLine $line): array => [
                        'invoice_number' => $line->invoice_number,
                        'base_amount' => (string) $line->base_amount,
                        'commission_amount' => (string) $line->commission_amount,
                    ])->all(),
                ],
            ])->save();

            return $locked;
        });
    }

    public function markPaid(Statement $statement, ?string $reference, ?string $paidOn): Statement
    {
        return DB::transaction(function () use ($statement, $reference, $paidOn): Statement {
            $locked = $this->lock($statement);
            $this->assertStatus($locked, [StatementStatus::Approved, StatementStatus::Invoiced], 'only_approved_can_be_paid');
            $this->assertPayoutNotBlocked($locked);
            $locked->forceFill([
                'status' => StatementStatus::Paid->value,
                'paid_at' => $paidOn === null ? CarbonImmutable::now() : CarbonImmutable::parse($paidOn),
                'payment_reference' => $reference,
            ])->save();

            return $locked;
        });
    }

    public function recordDispatch(Statement $statement, string $recipient): void
    {
        $statement->forceFill([
            'sent_at' => CarbonImmutable::now(),
            'sent_to' => $recipient,
            'sent_count' => (int) $statement->sent_count + 1,
        ])->save();
    }

    /**
     * @return array{lines: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function compute(string $tenantId, AgentProfile $profile, string $from, string $to, ?AgentYearBalance $balance): array
    {
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);

        if ($end->lt($start)) {
            throw new AgentCommissionException('The end of the period is before its start.', 'invalid_period');
        }

        if ($start->year !== $end->year) {
            throw new AgentCommissionException('A statement cannot span two calendar years: the Enasarco ceiling is yearly.', 'period_spans_years');
        }

        $lastEnd = Statement::query()
            ->where('agent_profile_id', $profile->id)
            ->where('status', '!=', StatementStatus::Void->value)
            ->max('period_end');

        if ($lastEnd !== null && $start->lte(CarbonImmutable::parse((string) $lastEnd))) {
            throw new AgentCommissionException('The period overlaps a statement already drawn for this agent.', 'period_overlap');
        }

        $balance ??= new AgentYearBalance(['commission_ytd' => '0', 'enasarco_base_ytd' => '0', 'enasarco_agent_ytd' => '0', 'enasarco_company_ytd' => '0', 'firr_ytd' => '0']);
        $lines = collect($this->lines($profile, $end));
        $own = Dec::sum($lines->where('role', EntryRole::Primary->value)->pluck('commission_amount'));
        $override = Dec::sum($lines->where('role', EntryRole::ParentOverride->value)->pluck('commission_amount'));
        $gross = Dec::round(Dec::add($own, $override));
        $fiscal = $this->engine->settle($tenantId, $profile, $end->year, $gross, $balance);

        return [
            'lines' => $lines->values()->all(),
            'summary' => [
                'agent_profile_id' => (string) $profile->id,
                'period_start' => $from,
                'period_end' => $to,
                'currency' => (string) ($lines->first()['currency'] ?? 'EUR'),
                'lines_count' => $lines->count(),
                'base_total' => Dec::round(Dec::sum($lines->where('role', EntryRole::Primary->value)->pluck('base_amount'))),
                'own_commission_total' => Dec::round($own),
                'override_commission_total' => Dec::round($override),
                'clawback_total' => Dec::round(Dec::sum($lines->filter(static fn (array $line): bool => Dec::isNegative($line['commission_amount']))->pluck('commission_amount'))),
                'gross_commission' => $gross,
                'fiscal' => $fiscal,
                'net_payable' => FiscalEngine::netPayable($gross, $fiscal),
                'blocked_amount' => $this->blockedAmount($profile),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(AgentProfile $profile, CarbonImmutable $end): array
    {
        $entries = CommissionEntry::query()
            ->where('agent_profile_id', $profile->id)
            ->whereColumn('eligible_amount', '!=', 'settled_amount')
            ->get();

        if ($entries->isEmpty()) {
            return [];
        }

        $eligibleAt = EntryMovement::query()
            ->whereIn('entry_id', $entries->modelKeys())
            ->whereDate('occurred_on', '<=', $end->toDateString())
            ->selectRaw('entry_id, sum(amount) as total')
            ->groupBy('entry_id')
            ->pluck('total', 'entry_id');
        $invoices = DB::table('invoices')->whereIn('id', $entries->pluck('invoice_id')->unique()->all())->get(['id', 'invoice_number', 'date', 'contact_id'])->keyBy('id');
        $contacts = DB::table('contacts')->whereIn('id', $invoices->pluck('contact_id')->unique()->all())->get(['id', 'company_name', 'first_name', 'last_name'])->keyBy('id');

        /** @return array<string, mixed>|null */
        $toLine = function (CommissionEntry $entry) use ($eligibleAt, $invoices, $contacts): ?array {
            $amount = Dec::round(Dec::sub(Dec::of(number_format((float) ($eligibleAt[$entry->id] ?? 0), 4, '.', '')), (string) $entry->settled_amount));

            if (Dec::isZero($amount) || Dec::isZero((string) $entry->commission_amount)) {
                return null;
            }

            $invoice = $invoices->get($entry->invoice_id);
            $contact = $invoice === null ? null : $contacts->get($invoice->contact_id);
            $customer = $contact === null ? null : (trim((string) $contact->company_name) !== '' ? $contact->company_name : trim($contact->first_name.' '.$contact->last_name));

            return [
                'entry_id' => (string) $entry->id,
                'invoice_id' => (string) $entry->invoice_id,
                'invoice_number' => $invoice?->invoice_number,
                'invoice_date' => $invoice?->date,
                'customer_name' => $customer,
                'role' => $entry->role,
                'accrual_basis' => $entry->accrual_basis,
                'currency' => $entry->currency,
                'base_amount' => Dec::round(Dec::div(Dec::mul((string) $entry->base_amount, $amount), (string) $entry->commission_amount)),
                'rate' => $entry->rate === null ? null : (string) $entry->rate,
                'commission_amount' => $amount,
            ];
        };

        return $entries->map($toLine)->filter()->sortBy(['invoice_date', 'invoice_number'])->values()->all();
    }

    /**
     * Commission earned on invoices whose collection is not (fully) reconciled: not payable yet.
     */
    private function blockedAmount(AgentProfile $profile): string
    {
        $entries = CommissionEntry::query()
            ->where('agent_profile_id', $profile->id)
            ->where('accrual_basis', AccrualBasis::Collected->value)
            ->whereIn('status', [EntryStatus::PendingCollection->value, EntryStatus::AwaitingReconciliation->value, EntryStatus::PartiallyEligible->value])
            ->get(['commission_amount', 'credited_amount', 'eligible_amount']);

        return Dec::round(Dec::sum($entries->map(static fn (CommissionEntry $entry): string => Dec::max('0', Dec::sub(Dec::sub((string) $entry->commission_amount, (string) $entry->credited_amount), (string) $entry->eligible_amount)))));
    }

    private function assertPayoutNotBlocked(Statement $statement): void
    {
        $blocked = StatementLine::query()
            ->where('statement_id', $statement->id)
            ->get()
            ->filter(function (StatementLine $line): bool {
                $entry = CommissionEntry::query()->find($line->entry_id);

                return $entry !== null && Dec::cmp((string) $entry->eligible_amount, (string) $entry->settled_amount) < 0;
            })
            ->pluck('invoice_number')
            ->unique()
            ->values();

        if ($blocked->isNotEmpty()) {
            throw new AgentCommissionException('Payout blocked: the collection of these invoices is no longer reconciled: '.$blocked->implode(', '), 'payout_blocked');
        }
    }

    private function settledStatus(CommissionEntry $entry, string $settled): string
    {
        $effective = Dec::sub((string) $entry->commission_amount, (string) $entry->credited_amount);

        return Dec::cmp($settled, (string) $entry->eligible_amount) === 0 && Dec::cmp((string) $entry->eligible_amount, $effective) === 0 && ! Dec::isZero($settled)
            ? EntryStatus::Settled->value
            : $entry->status;
    }

    private function balanceFor(string $tenantId, AgentProfile $profile, int $year): AgentYearBalance
    {
        $balance = AgentYearBalance::query()->where('agent_profile_id', $profile->id)->where('year', $year)->lockForUpdate()->first();

        return $balance ?? AgentYearBalance::query()->create(['tenant_id' => $tenantId, 'agent_profile_id' => $profile->id, 'year' => $year, 'commission_ytd' => '0', 'enasarco_base_ytd' => '0', 'enasarco_agent_ytd' => '0', 'enasarco_company_ytd' => '0', 'firr_ytd' => '0']);
    }

    /**
     * @return array<string, mixed>
     */
    private function balanceArray(AgentYearBalance $balance): array
    {
        return [
            'commission_ytd' => (string) $balance->commission_ytd,
            'enasarco_base_ytd' => (string) $balance->enasarco_base_ytd,
            'enasarco_agent_ytd' => (string) $balance->enasarco_agent_ytd,
            'enasarco_company_ytd' => (string) $balance->enasarco_company_ytd,
            'firr_ytd' => (string) $balance->firr_ytd,
            'massimale' => $balance->massimale === null ? null : (string) $balance->massimale,
            'minimale' => $balance->minimale === null ? null : (string) $balance->minimale,
        ];
    }

    private function lock(Statement $statement): Statement
    {
        return Statement::query()->lockForUpdate()->findOrFail($statement->id);
    }

    /**
     * @param  list<StatementStatus>  $allowed
     */
    private function assertStatus(Statement $statement, array $allowed, string $code): void
    {
        if (! in_array($statement->status, array_map(static fn (StatementStatus $status): string => $status->value, $allowed), true)) {
            throw new AgentCommissionException('The statement is in the wrong status for this action.', $code, 409);
        }
    }

    private function nextNumber(string $prefix, string $column, int $year): string
    {
        $pattern = $prefix.'-'.$year.'-';
        $last = Statement::withTrashed()
            ->where($column, 'like', $pattern.'%')
            ->orderByDesc($column)
            ->value($column);
        $next = $last === null ? 1 : ((int) substr((string) $last, strlen($pattern))) + 1;

        return $pattern.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
