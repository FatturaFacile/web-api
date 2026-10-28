<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Contracts\PeriodClosingServiceInterface;
use Modules\GeneralLedger\Events\PeriodClosedEvent;
use Modules\GeneralLedger\Events\PeriodOpenedEvent;
use Modules\GeneralLedger\Events\YearClosedEvent;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FinancialClosingRun;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\PeriodLock;

final class PeriodClosingService implements PeriodClosingServiceInterface
{
    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly AuditLedgerInterface $auditLedger,
        private readonly DoubleEntryPostingServiceInterface $postingService
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

            // Verify no draft entries
            $draftCount = JournalEntry::where('fiscal_period_id', $period->id)
                ->where('status', 'draft')
                ->count();

            if ($draftCount > 0) {
                throw new InvalidArgumentException("Cannot close fiscal period [{$period->period_name}]: {$draftCount} unposted draft journal entries exist.");
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
                throw new InvalidArgumentException("Cannot reopen fiscal period [{$period->period_name}]: parent fiscal year [{$period->fiscalYear->year_code}] is closed.");
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
                throw new InvalidArgumentException("Fiscal year [{$fy->year_code}] is already closed.");
            }

            // Check that all 12 periods are closed
            $openPeriods = FiscalPeriod::where('fiscal_year_id', $fy->id)
                ->where('period_number', '<=', 12)
                ->where('status', '!=', 'closed')
                ->count();

            if ($openPeriods > 0) {
                throw new InvalidArgumentException("Cannot close fiscal year [{$fy->year_code}]: {$openPeriods} fiscal periods are still open.");
            }

            // Find or use adjustment period 13 or period 12 for closing entries
            $closingPeriod = FiscalPeriod::where('fiscal_year_id', $fy->id)
                ->where('period_number', 13)
                ->first() ?? FiscalPeriod::where('fiscal_year_id', $fy->id)->where('period_number', 12)->firstOrFail();

            // Calculate revenue and expenses across year
            $pnlLines = DB::table('journal_lines')
                ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
                ->join('chart_of_accounts', 'journal_lines.account_id', '=', 'chart_of_accounts.id')
                ->where('journal_entries.tenant_id', $fy->tenant_id)
                ->whereIn('journal_entries.fiscal_period_id', $fy->periods->pluck('id'))
                ->where('journal_entries.status', 'posted')
                ->whereIn('chart_of_accounts.account_type', ['revenue', 'expense', 'cogs'])
                ->select(
                    'chart_of_accounts.id as account_id',
                    'chart_of_accounts.account_code',
                    'chart_of_accounts.account_type',
                    DB::raw('SUM(journal_lines.debit_amount) as total_dr'),
                    DB::raw('SUM(journal_lines.credit_amount) as total_cr')
                )
                ->groupBy('chart_of_accounts.id', 'chart_of_accounts.account_code', 'chart_of_accounts.account_type')
                ->get();

            $totalRevenue = 0.0;
            $totalExpense = 0.0;
            $closingLines = [];

            foreach ($pnlLines as $row) {
                $dr = (float) $row->total_dr;
                $cr = (float) $row->total_cr;

                if ($row->account_type === 'revenue') {
                    $netRev = $cr - $dr;
                    $totalRevenue += $netRev;
                    // To zero out revenue: debit revenue account
                    if ($netRev > 0) {
                        $closingLines[] = [
                            'account_id' => $row->account_id,
                            'debit_amount' => $netRev,
                            'credit_amount' => 0.0,
                            'description' => 'Year-end revenue closing to retained earnings',
                        ];
                    }
                } else {
                    $netExp = $dr - $cr;
                    $totalExpense += $netExp;
                    // To zero out expense: credit expense account
                    if ($netExp > 0) {
                        $closingLines[] = [
                            'account_id' => $row->account_id,
                            'debit_amount' => 0.0,
                            'credit_amount' => $netExp,
                            'description' => 'Year-end expense closing to retained earnings',
                        ];
                    }
                }
            }

            $netIncome = $totalRevenue - $totalExpense;

            // Retained Earnings Account (Equity 3000)
            $retainedEarningsAccount = ChartOfAccount::where('tenant_id', $fy->tenant_id)
                ->where(function ($q) {
                    $q->where('account_code', '3000')
                        ->orWhere('account_type', 'equity');
                })
                ->first();

            if (! $retainedEarningsAccount) {
                $retainedEarningsAccount = ChartOfAccount::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $fy->tenant_id,
                    'company_id' => $fy->company_id,
                    'account_code' => '3000',
                    'name' => 'Retained Earnings',
                    'account_class' => 'equity',
                    'account_type' => 'equity',
                    'normal_balance' => 'credit',
                    'currency' => 'EUR',
                    'is_active' => true,
                ]);
            }

            if ($netIncome > 0) {
                // Credit Retained Earnings
                $closingLines[] = [
                    'account_id' => $retainedEarningsAccount->id,
                    'debit_amount' => 0.0,
                    'credit_amount' => $netIncome,
                    'description' => 'Net Income to Retained Earnings',
                ];
            } elseif ($netIncome < 0) {
                // Debit Retained Earnings
                $closingLines[] = [
                    'account_id' => $retainedEarningsAccount->id,
                    'debit_amount' => abs($netIncome),
                    'credit_amount' => 0.0,
                    'description' => 'Net Loss from Retained Earnings',
                ];
            }

            $closingJournal = null;
            if (! empty($closingLines)) {
                $closingJournal = $this->postingService->createAndPost([
                    'tenant_id' => $fy->tenant_id,
                    'company_id' => $fy->company_id,
                    'fiscal_period_id' => $closingPeriod->id,
                    'entry_number' => 'YE-CLOSE-'.$fy->year_code,
                    'posting_date' => $fy->end_date,
                    'document_date' => $fy->end_date,
                    'journal_type' => 'closing',
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
                netIncomeAmount: $netIncome,
                closingJournalId: (string) ($closingJournal?->id ?? ''),
                closedBy: $closedByUuid,
                tenantId: (string) $fy->tenant_id,
                companyId: (string) ($fy->company_id ?? '')
            );
            $this->dispatcher->publish($event);
            event($event);

            return $closingRun;
        });
    }
}
