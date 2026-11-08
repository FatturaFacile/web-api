<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Exceptions\LedgerConflictException;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\PeriodCloseTask;
use Modules\GeneralLedger\Models\RecurringJournal;

/**
 * The close checklist of a fiscal period: automatic checks read from the ledger, plus the tasks the users add (bank
 * reconciliation signed off, inventory count booked, ...). The blocking items stop the closing of the period:
 * unposted drafts, journals waiting for approval, recurring runs and automatic reversals due in the period and not yet
 * posted, unbalanced entries, and the open blocking tasks. The other checks are informative.
 */
final class PeriodCloseChecklistService
{
    /**
     * Every check and task of the period with its state.
     *
     * @return array{fiscal_period_id: string, period_name: string, status: string, can_close: bool, checks: list<array{code: string, label: string, blocking: bool, passed: bool, count: int}>, tasks: list<PeriodCloseTask>}
     */
    public function checklist(FiscalPeriod $period): array
    {
        $checks = $this->checks($period);
        $tasks = PeriodCloseTask::where('fiscal_period_id', $period->id)->orderBy('created_at')->get();
        $openBlockingTasks = $tasks->filter(static fn (PeriodCloseTask $task): bool => $task->is_blocking && $task->status === 'open')->count();

        $canClose = $openBlockingTasks === 0 && collect($checks)->every(static fn (array $check): bool => ! $check['blocking'] || $check['passed']);

        return [
            'fiscal_period_id' => (string) $period->id,
            'period_name' => (string) $period->period_name,
            'status' => (string) $period->status,
            'can_close' => $canClose,
            'checks' => $checks,
            'tasks' => $tasks->all(),
        ];
    }

    /**
     * The messages of the blocking items not yet cleared; empty when the period can be closed.
     *
     * @return list<string>
     */
    public function blockingIssues(FiscalPeriod $period): array
    {
        $issues = [];

        foreach ($this->checks($period) as $check) {
            if ($check['blocking'] && ! $check['passed']) {
                $issues[] = "{$check['label']} ({$check['count']}).";
            }
        }

        $openTasks = PeriodCloseTask::withoutGlobalScopes()->where('fiscal_period_id', $period->id)->whereNull('deleted_at')->where('is_blocking', true)->where('status', 'open')->count();

        if ($openTasks > 0) {
            $issues[] = "Blocking close tasks still open ({$openTasks}).";
        }

        return $issues;
    }

    /**
     * Adds a task to the checklist of an open period.
     *
     * @param  array{title: string, description?: ?string, is_blocking?: bool}  $data
     *
     * @throws LedgerConflictException when the period is already closed
     */
    public function addTask(FiscalPeriod $period, array $data, string $actorId): PeriodCloseTask
    {
        if ($period->status === 'closed') {
            throw new LedgerConflictException("Fiscal period [{$period->period_name}] is closed: its checklist cannot change.");
        }

        return PeriodCloseTask::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $period->tenant_id,
            'fiscal_period_id' => $period->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'is_blocking' => (bool) ($data['is_blocking'] ?? true),
            'status' => 'open',
            'created_by' => $actorId,
        ]);
    }

    /**
     * Marks a task done, waived or open again.
     *
     * @throws LedgerConflictException when the period is already closed
     */
    public function setTaskStatus(PeriodCloseTask $task, string $status, ?string $note, string $actorId): PeriodCloseTask
    {
        return DB::transaction(function () use ($task, $status, $note, $actorId): PeriodCloseTask {
            $period = FiscalPeriod::whereKey($task->fiscal_period_id)->lockForUpdate()->firstOrFail();

            if ($period->status === 'closed') {
                throw new LedgerConflictException("Fiscal period [{$period->period_name}] is closed: its checklist cannot change.");
            }

            $task->update([
                'status' => $status,
                'completion_note' => $note,
                'completed_by' => $status === 'open' ? null : $actorId,
                'completed_at' => $status === 'open' ? null : now(),
            ]);

            return $task;
        });
    }

    /**
     * @return list<array{code: string, label: string, blocking: bool, passed: bool, count: int}>
     */
    private function checks(FiscalPeriod $period): array
    {
        $tenantId = (string) $period->tenant_id;
        $start = CarbonImmutable::parse($period->start_date)->toDateString();
        $end = CarbonImmutable::parse($period->end_date)->toDateString();
        $entries = static fn () => JournalEntry::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at');

        $drafts = $entries()->where('fiscal_period_id', $period->id)->where('status', 'draft')->count();
        $pending = $entries()->where('fiscal_period_id', $period->id)->where('status', 'pending_approval')->count();
        $reversalsDue = $entries()->where('status', 'posted')->whereNotNull('auto_reverse_on')->whereBetween('auto_reverse_on', [$start, $end])->count();
        $recurringDue = RecurringJournal::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('status', 'active')->whereDate('next_run_date', '<=', $end)->count();

        $unbalanced = DB::query()->fromSub(DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.fiscal_period_id', $period->id)
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->whereNull('journal_entries.deleted_at')
            ->whereNull('journal_lines.deleted_at')
            ->groupBy('journal_entries.id')
            ->havingRaw('SUM(journal_lines.debit_amount) <> SUM(journal_lines.credit_amount)')
            ->select('journal_entries.id'), 'unbalanced_entries')
            ->count();

        $controlDifference = $this->subledgerDifference($tenantId, $end);

        return [
            ['code' => 'no_drafts', 'label' => 'Draft journals not yet posted', 'blocking' => true, 'passed' => $drafts === 0, 'count' => $drafts],
            ['code' => 'no_pending_approvals', 'label' => 'Journals waiting for approval', 'blocking' => true, 'passed' => $pending === 0, 'count' => $pending],
            ['code' => 'recurring_journals_posted', 'label' => 'Recurring journals due and not yet posted', 'blocking' => true, 'passed' => $recurringDue === 0, 'count' => $recurringDue],
            ['code' => 'automatic_reversals_posted', 'label' => 'Automatic reversals due in the period and not yet posted', 'blocking' => true, 'passed' => $reversalsDue === 0, 'count' => $reversalsDue],
            ['code' => 'entries_balanced', 'label' => 'Posted entries whose lines do not balance', 'blocking' => true, 'passed' => $unbalanced === 0, 'count' => $unbalanced],
            ['code' => 'subledgers_reconciled', 'label' => 'Control accounts differing from their open items (customers, suppliers)', 'blocking' => false, 'passed' => $controlDifference === 0, 'count' => $controlDifference],
        ];
    }

    /**
     * How many of the customer and supplier control sides differ from the sum of their open items at the date, read in
     * the accounting currency of the lines (informative: open items in a foreign currency are kept in that currency).
     */
    private function subledgerDifference(string $tenantId, string $end): int
    {
        $differences = 0;

        foreach (['customer' => ['customer_ledger', '1'], 'supplier' => ['supplier_ledger', '-1']] as $type => [$table, $sign]) {
            $ledger = (string) (DB::table('journal_lines')
                ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
                ->join('chart_of_accounts', 'journal_lines.account_id', '=', 'chart_of_accounts.id')
                ->where('journal_entries.tenant_id', $tenantId)
                ->whereIn('journal_entries.status', ['posted', 'reversed'])
                ->where('journal_entries.journal_type', '!=', 'opening')
                ->whereNull('journal_entries.deleted_at')
                ->whereNull('journal_lines.deleted_at')
                ->where('journal_entries.posting_date', '<=', $end)
                ->where('chart_of_accounts.reconciliation_type', $type)
                ->selectRaw('COALESCE(SUM(journal_lines.debit_amount - journal_lines.credit_amount), 0) as balance')
                ->value('balance') ?? '0');

            $open = (string) (DB::table($table)
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->where('posting_date', '<=', $end)
                ->selectRaw('COALESCE(SUM(open_amount), 0) as open_total')
                ->value('open_total') ?? '0');

            if (bccomp(bcmul($ledger, $sign, 4), $open, 4) !== 0) {
                $differences++;
            }
        }

        return $differences;
    }
}
