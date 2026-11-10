<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Carbon\CarbonImmutable;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Contracts\PeriodClosingServiceInterface;
use Modules\GeneralLedger\Enums\PostingAccountRole;
use Modules\GeneralLedger\Events\PeriodClosedEvent;
use Modules\GeneralLedger\Events\PeriodOpenedEvent;
use Modules\GeneralLedger\Events\YearClosedEvent;
use Modules\GeneralLedger\Exceptions\LedgerConflictException;
use Modules\GeneralLedger\Exceptions\LedgerPostingException;
use Modules\GeneralLedger\Models\FinancialClosingRun;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\PeriodLock;

/**
 * Month-end and year-end closing of the ledger: period locks per subledger, the close checklist gate, the year-end
 * closing entry of the income statement (chiusura) and the opening entry of the next year (riapertura).
 */
final class PeriodClosingService implements PeriodClosingServiceInterface
{
    public const string OpeningBalanceSource = 'opening_balance';

    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly AuditLedgerInterface $auditLedger,
        private readonly DoubleEntryPostingServiceInterface $postingService,
        private readonly LedgerBalanceReader $balances,
        private readonly PostingAccountResolver $postingAccounts,
        private readonly PeriodCloseChecklistService $checklist,
    ) {}

    public function closePeriod(string $fiscalPeriodId, string $closedBy): FiscalPeriod
    {
        $closedByUuid = Str::isUuid($closedBy) ? $closedBy : (string) Str::uuid7();

        return DB::transaction(function () use ($fiscalPeriodId, $closedByUuid) {
            /** @var FiscalPeriod $period */
            $period = FiscalPeriod::where('id', $fiscalPeriodId)->lockForUpdate()->firstOrFail();

            if ($period->status === 'closed') {
                return $period;
            }

            $blocking = $this->checklist->blockingIssues($period);

            if ($blocking !== []) {
                throw new LedgerConflictException("Cannot close fiscal period [{$period->period_name}]: ".implode(' ', $blocking));
            }

            $before = $period->toArray();
            $period->update([
                'status' => 'closed',
            ]);

            // Lock all subledgers
            $subledgers = ['GL', 'AR', 'AP', 'INVENTORY', 'PAYROLL', 'FIXED_ASSETS', 'PROJECTS'];
            foreach ($subledgers as $sub) {
                PeriodLock::updateOrCreate(
                    [
                        'tenant_id' => $period->tenant_id,
                        'fiscal_period_id' => $period->id,
                        'subledger_module' => $sub,
                    ],
                    [
                        'id' => (string) Str::uuid7(),
                        'is_locked' => true,
                        'locked_by' => $closedByUuid,
                        'locked_at' => now(),
                    ]
                );
            }

            $this->auditLedger->record(
                action: 'period_closed',
                model: $period,
                payloadBefore: $before,
                payloadAfter: $period->toArray(),
                moduleCode: 'MODULE_22_GENERAL_LEDGER'
            );

            $event = new PeriodClosedEvent(
                fiscalPeriodId: (string) $period->id,
                fiscalYearId: (string) $period->fiscal_year_id,
                periodNumber: (int) $period->period_number,
                periodName: (string) $period->period_name,
                closedBy: $closedByUuid,
                tenantId: (string) $period->tenant_id,
                companyId: (string) ($period->company_id ?? '')
            );
            $this->dispatcher->publish($event);
            event($event);

            return $period;
        });
    }

    public function reopenPeriod(string $fiscalPeriodId, string $openedBy): FiscalPeriod
    {
        return DB::transaction(function () use ($fiscalPeriodId) {
            /** @var FiscalPeriod $period */
            $period = FiscalPeriod::where('id', $fiscalPeriodId)->lockForUpdate()->firstOrFail();

            if ($period->fiscalYear && $period->fiscalYear->status === 'closed') {
                throw new LedgerConflictException("Cannot reopen fiscal period [{$period->period_name}]: parent fiscal year [{$period->fiscalYear->year_code}] is closed.");
            }

            $before = $period->toArray();
            $period->update([
                'status' => 'open',
            ]);

            // Unlock subledgers
            PeriodLock::where('fiscal_period_id', $period->id)->update([
                'is_locked' => false,
            ]);

            $this->auditLedger->record(
                action: 'period_reopened',
                model: $period,
                payloadBefore: $before,
                payloadAfter: $period->toArray(),
                moduleCode: 'MODULE_22_GENERAL_LEDGER'
            );

            $event = new PeriodOpenedEvent(
                fiscalPeriodId: (string) $period->id,
                fiscalYearId: (string) $period->fiscal_year_id,
                periodNumber: (int) $period->period_number,
                tenantId: (string) $period->tenant_id,
                companyId: (string) ($period->company_id ?? '')
            );
            $this->dispatcher->publish($event);
            event($event);

            return $period;
        });
    }

    public function lockSubledger(string $fiscalPeriodId, string $subledger, string $lockedBy): PeriodLock
    {
        $period = FiscalPeriod::findOrFail($fiscalPeriodId);
        $lockedByUuid = Str::isUuid($lockedBy) ? $lockedBy : (string) Str::uuid7();

        return PeriodLock::updateOrCreate(
            [
                'tenant_id' => $period->tenant_id,
                'fiscal_period_id' => $period->id,
                'subledger_module' => strtoupper($subledger),
            ],
            [
                'id' => (string) Str::uuid7(),
                'is_locked' => true,
                'locked_by' => $lockedByUuid,
                'locked_at' => now(),
            ]
        );
    }

    public function closeFiscalYear(string $fiscalYearId, string $closedBy): FinancialClosingRun
    {
        $closedByUuid = Str::isUuid($closedBy) ? $closedBy : (string) Str::uuid7();

        return DB::transaction(function () use ($fiscalYearId, $closedByUuid) {
            /** @var FiscalYear $fy */
            $fy = FiscalYear::where('id', $fiscalYearId)->lockForUpdate()->firstOrFail();

            if ($fy->status === 'closed') {
                throw new LedgerConflictException("Fiscal year [{$fy->year_code}] is already closed.");
            }

            // Check that all 12 periods are closed
            $openPeriods = FiscalPeriod::where('fiscal_year_id', $fy->id)
                ->where('period_number', '<=', 12)
                ->where('status', '!=', 'closed')
                ->count();

            if ($openPeriods > 0) {
                throw new LedgerConflictException("Cannot close fiscal year [{$fy->year_code}]: {$openPeriods} fiscal periods are still open.");
            }

            // Find or use adjustment period 13 or period 12 for closing entries
            $closingPeriod = FiscalPeriod::where('fiscal_year_id', $fy->id)
                ->where('period_number', 13)
                ->first() ?? FiscalPeriod::where('fiscal_year_id', $fy->id)->where('period_number', 12)->firstOrFail();

            $priorCloses = FinancialClosingRun::where('tenant_id', $fy->tenant_id)
                ->where('fiscal_period_id', $closingPeriod->id)
                ->where('closing_type', 'year_end')
                ->count();
            $entrySuffix = $priorCloses > 0 ? '-R'.$priorCloses : '';

            [$closingLines, $netIncome] = $this->resultClosingLines($fy);
            $closingJournal = null;
            if (! empty($closingLines)) {
                $closingJournal = $this->postingService->postYearEndClosing([
                    'tenant_id' => $fy->tenant_id,
                    'company_id' => $fy->company_id,
                    'fiscal_period_id' => $closingPeriod->id,
                    'entry_number' => 'YE-CLOSE-'.$fy->year_code.$entrySuffix,
                    'posting_date' => $fy->end_date,
                    'document_date' => $fy->end_date,
                    'description' => "Year-end financial closing for {$fy->year_code}",
                    'lines' => $closingLines,
                ], $closedByUuid);
            }

            $fy->update([
                'status' => 'closed',
                'is_locked' => true,
                'closed_at' => now(),
                'closed_by' => $closedByUuid,
            ]);

            $closingRun = FinancialClosingRun::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $fy->tenant_id,
                'company_id' => $fy->company_id,
                'fiscal_period_id' => $closingPeriod->id,
                'closing_type' => 'year_end',
                'status' => 'completed',
                'closing_journal_id' => $closingJournal?->id,
                'net_income_amount' => $netIncome,
                'executed_by' => $closedByUuid,
                'executed_at' => now(),
            ]);

            $this->auditLedger->record(
                action: 'year_closed',
                model: $fy,
                payloadBefore: null,
                payloadAfter: $fy->toArray(),
                moduleCode: 'MODULE_22_GENERAL_LEDGER'
            );

            $event = new YearClosedEvent(
                fiscalYearId: (string) $fy->id,
                yearCode: (string) $fy->year_code,
                netIncomeAmount: (float) $netIncome,
                closingJournalId: (string) ($closingJournal?->id ?? ''),
                closedBy: $closedByUuid,
                tenantId: (string) $fy->tenant_id,
                companyId: (string) ($fy->company_id ?? '')
            );
            $this->dispatcher->publish($event);
            event($event);

            $this->carryForwardOpeningBalances((string) $fy->id, $closedByUuid);

            return $closingRun;
        });
    }

    /**
     * Reopens a closed fiscal year prior to General Assembly (AGM) approval (CO Art. 958c / 698).
     * Reverses the year-end P&L closing entry and any carry-forward opening balance entry in the next year,
     * and unlocks the final adjustment/December period for pre-AGM audit adjustments.
     */
    public function reopenFiscalYear(string $fiscalYearId, string $actorId, string $reason): FiscalYear
    {
        $actorUuid = Str::isUuid($actorId) ? $actorId : (string) Str::uuid7();

        return DB::transaction(function () use ($fiscalYearId, $actorUuid, $reason): FiscalYear {
            /** @var FiscalYear $fy */
            $fy = FiscalYear::where('id', $fiscalYearId)->lockForUpdate()->firstOrFail();

            if ($fy->agm_approved_at !== null) {
                throw new LedgerConflictException("Cannot reopen fiscal year [{$fy->year_code}]: financial statements have already been approved by the General Assembly (CO Art. 958c / 698).");
            }

            if ($fy->status !== 'closed') {
                throw new LedgerConflictException("Fiscal year [{$fy->year_code}] is not closed.");
            }

            $before = $fy->toArray();

            // Unlock the last period (period 13 if present, otherwise period 12) so reversal & audit adjustments can post
            $closingPeriods = FiscalPeriod::where('fiscal_year_id', $fy->id)
                ->whereIn('period_number', [12, 13])
                ->get();

            foreach ($closingPeriods as $cp) {
                $cp->update(['status' => 'open']);
                PeriodLock::where('fiscal_period_id', $cp->id)->update(['is_locked' => false]);
            }

            // Reverse the latest completed year-end closing journal entry if posted
            $closingRun = FinancialClosingRun::where('tenant_id', $fy->tenant_id)
                ->whereIn('fiscal_period_id', $closingPeriods->pluck('id'))
                ->where('closing_type', 'year_end')
                ->where('status', 'completed')
                ->latest('executed_at')
                ->first();

            if ($closingRun !== null) {
                if ($closingRun->closing_journal_id !== null) {
                    $closingJournal = JournalEntry::find($closingRun->closing_journal_id);
                    if ($closingJournal !== null && $closingJournal->status === 'posted') {
                        $this->postingService->reverseJournal(
                            (string) $closingJournal->id,
                            CarbonImmutable::parse($fy->end_date)->toDateString(),
                            "Fiscal year {$fy->year_code} reopened: {$reason}",
                            $actorUuid,
                            true
                        );
                    }
                }
                $closingRun->update(['status' => 'reversed']);
            }

            // Reverse the carry-forward opening balance entry in the next year if posted
            $openingEntries = JournalEntry::where('tenant_id', $fy->tenant_id)
                ->where('source_document_type', self::OpeningBalanceSource)
                ->where('source_document_id', $fy->id)
                ->where('status', 'posted')
                ->where('journal_type', '!=', 'reversing')
                ->get();

            foreach ($openingEntries as $openingEntry) {
                PeriodLock::where('fiscal_period_id', $openingEntry->fiscal_period_id)->update(['is_locked' => false]);
                $this->postingService->reverseJournal(
                    (string) $openingEntry->id,
                    CarbonImmutable::parse($openingEntry->posting_date)->toDateString(),
                    "Prior fiscal year {$fy->year_code} reopened: {$reason}",
                    $actorUuid,
                    true
                );
            }

            $fy->update([
                'status' => 'open',
                'is_locked' => false,
                'reopened_at' => now(),
                'reopened_by' => $actorUuid,
                'reopen_reason' => $reason,
            ]);

            $this->auditLedger->record(
                action: 'year_reopened',
                model: $fy,
                payloadBefore: $before,
                payloadAfter: $fy->fresh()?->toArray() ?? $fy->toArray(),
                moduleCode: 'MODULE_22_GENERAL_LEDGER'
            );

            return $fy->fresh(['periods']) ?? $fy;
        });
    }

    /**
     * Records General Assembly (AGM) approval of the annual financial statements (CO Art. 958c / 698),
     * permanently locking the fiscal year against reopening.
     */
    public function approveFiscalYearAtAgm(string $fiscalYearId, string $actorId, ?string $resolutionReference = null): FiscalYear
    {
        $actorUuid = Str::isUuid($actorId) ? $actorId : (string) Str::uuid7();

        return DB::transaction(function () use ($fiscalYearId, $actorUuid, $resolutionReference): FiscalYear {
            /** @var FiscalYear $fy */
            $fy = FiscalYear::where('id', $fiscalYearId)->lockForUpdate()->firstOrFail();

            if ($fy->status !== 'closed') {
                throw new LedgerConflictException("Fiscal year [{$fy->year_code}] must be closed before General Assembly (AGM) approval.");
            }

            if ($fy->agm_approved_at !== null) {
                return $fy;
            }

            $before = $fy->toArray();

            $fy->update([
                'is_locked' => true,
                'agm_approved_at' => now(),
                'agm_approved_by' => $actorUuid,
                'agm_resolution_reference' => $resolutionReference,
            ]);

            $this->auditLedger->record(
                action: 'year_agm_approved',
                model: $fy,
                payloadBefore: $before,
                payloadAfter: $fy->fresh()?->toArray() ?? $fy->toArray(),
                moduleCode: 'MODULE_22_GENERAL_LEDGER'
            );

            return $fy->fresh(['periods']) ?? $fy;
        });
    }

    /**
     * The next year is the one starting the day after. A calendar year opens it if needed; any other year must have it
     * in place first, and then null comes back. Lines carry no partner, so no open item is created twice, and the bank
     * balance stays as it is because the money did not move.
     *
     * @throws InvalidArgumentException when the year is not closed or its balances do not square
     */
    public function carryForwardOpeningBalances(string $fiscalYearId, string $actorId): ?JournalEntry
    {
        return DB::transaction(function () use ($fiscalYearId, $actorId): ?JournalEntry {
            /** @var FiscalYear $fy */
            $fy = FiscalYear::where('id', $fiscalYearId)->lockForUpdate()->firstOrFail();

            if ($fy->status !== 'closed') {
                throw new LedgerConflictException("Fiscal year [{$fy->year_code}] must be closed before carrying its balances forward.");
            }

            $existing = JournalEntry::where('tenant_id', $fy->tenant_id)
                ->where('source_document_type', self::OpeningBalanceSource)
                ->where('source_document_id', $fy->id)
                ->where('status', 'posted')
                ->where('journal_type', '!=', 'reversing')
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $nextYear = $this->nextYear($fy);

            if ($nextYear === null) {
                return null;
            }

            $lines = $this->openingLines($fy);

            if ($lines === []) {
                return null;
            }

            $priorOpenings = JournalEntry::where('tenant_id', $fy->tenant_id)
                ->where('source_document_type', self::OpeningBalanceSource)
                ->where('source_document_id', $fy->id)
                ->count();
            $entrySuffix = $priorOpenings > 0 ? '-R'.$priorOpenings : '';

            $firstPeriod = FiscalPeriod::where('fiscal_year_id', $nextYear->id)->orderBy('start_date')->orderBy('period_number')->firstOrFail();

            return $this->postingService->createAndPost([
                'tenant_id' => $fy->tenant_id,
                'company_id' => $fy->company_id,
                'fiscal_period_id' => $firstPeriod->id,
                'entry_number' => 'OPEN-'.$nextYear->year_code.$entrySuffix,
                'journal_type' => 'opening',
                'posting_date' => CarbonImmutable::parse($nextYear->start_date)->toDateString(),
                'document_date' => CarbonImmutable::parse($nextYear->start_date)->toDateString(),
                'description' => "Opening balances carried forward from {$fy->year_code}",
                'source_document_type' => self::OpeningBalanceSource,
                'source_document_id' => $fy->id,
                'bank_balance_managed' => true,
                'lines' => $lines,
            ], $actorId);
        });
    }

    /**
     * The lines that bring every income statement account of the year to zero against the retained earnings account
     * (utile/perdita d'esercizio), and the result of the year. Accounts are closed on whichever side they stand, so an
     * expense account with a credit balance or a revenue account with a debit balance is closed too and the entry
     * always balances. Reversed entries and their reversals are both read, so they cancel out.
     *
     * @return array{0: list<array{account_id: string, debit_amount: string, credit_amount: string, description: string}>, 1: string}
     *
     * @throws LedgerPostingException when the year has a result and no retained earnings account is configured
     */
    private function resultClosingLines(FiscalYear $fy): array
    {
        $movements = $this->balances->movements((string) $fy->tenant_id, CarbonImmutable::parse($fy->start_date), CarbonImmutable::parse($fy->end_date))
            ->filter(static fn (object $row): bool => in_array($row->account_type, LedgerBalanceReader::IncomeTypes, true));

        $lines = [];
        $netIncome = '0';

        foreach ($movements as $row) {
            $creditMinusDebit = bcsub((string) $row->credit, (string) $row->debit, 4);

            if (bccomp($creditMinusDebit, '0', 4) === 0) {
                continue;
            }

            $netIncome = bcadd($netIncome, $creditMinusDebit, 4);
            $isCreditBalance = bccomp($creditMinusDebit, '0', 4) === 1;
            $lines[] = [
                'account_id' => (string) $row->account_id,
                'debit_amount' => $isCreditBalance ? $creditMinusDebit : '0',
                'credit_amount' => $isCreditBalance ? '0' : ltrim($creditMinusDebit, '-'),
                'description' => "Year-end closing of {$row->account_code} to the result of the year",
            ];
        }

        if ($lines === []) {
            return [[], '0.0000'];
        }

        if (bccomp($netIncome, '0', 4) !== 0) {
            $retainedEarnings = $this->postingAccounts->require((string) $fy->tenant_id, PostingAccountRole::RetainedEarnings);
            $isProfit = bccomp($netIncome, '0', 4) === 1;
            $lines[] = [
                'account_id' => (string) $retainedEarnings->id,
                'debit_amount' => $isProfit ? '0' : ltrim($netIncome, '-'),
                'credit_amount' => $isProfit ? $netIncome : '0',
                'description' => $isProfit ? 'Net income of the year to retained earnings' : 'Net loss of the year from retained earnings',
            ];
        }

        return [$lines, $netIncome];
    }

    private function nextYear(FiscalYear $fy): ?FiscalYear
    {
        $end = CarbonImmutable::parse($fy->end_date);
        $next = FiscalYear::where('tenant_id', $fy->tenant_id)->whereDate('start_date', $end->addDay()->toDateString())->first();

        if ($next !== null) {
            return $next;
        }

        $isCalendarYear = CarbonImmutable::parse($fy->start_date)->dayOfYear === 1 && $end->isSameDay($end->endOfYear());

        return $isCalendarYear ? (new OpenFiscalYearAction)->execute((string) $fy->tenant_id, $end->year + 1) : null;
    }

    /**
     * @return list<array{account_id: string, debit_amount: string, credit_amount: string, description: string}>
     */
    private function openingLines(FiscalYear $fy): array
    {
        $lines = [];

        foreach ($this->balances->closedBalances((string) $fy->tenant_id, CarbonImmutable::parse($fy->end_date)) as $account) {
            $net = bcsub((string) $account->debit, (string) $account->credit, 4);

            if (bccomp($net, '0', 4) === 0) {
                continue;
            }

            $isDebit = bccomp($net, '0', 4) > 0;
            $lines[] = [
                'account_id' => (string) $account->account_id,
                'debit_amount' => $isDebit ? $net : '0',
                'credit_amount' => $isDebit ? '0' : ltrim($net, '-'),
                'description' => "Opening balance from {$fy->year_code}",
            ];
        }

        return $lines;
    }
}
