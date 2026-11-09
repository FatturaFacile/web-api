<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Exceptions\LedgerConflictException;
use Modules\GeneralLedger\Exceptions\LedgerPostingException;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\RecurringJournal;
use Modules\GeneralLedger\Models\RecurringJournalRun;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Throwable;

/**
 * Recurring journals: a balanced template posted at every due date. Each run is a manual journal of the template's
 * lines on the due date, in the fiscal period of that date, subject to the same approval policy as any manual
 * journal; a template can ask for every run to be reversed on the next day (accrual-style recurring entries).
 */
final readonly class RecurringJournalService
{
    public function __construct(
        private DoubleEntryPostingServiceInterface $postingService,
        private ManualJournalService $manualJournals,
        private FiscalPeriodResolver $periods,
    ) {}

    /**
     * Saves a template. The lines must balance and respect the manual posting rules of their accounts.
     *
     * @param  array{name: string, description: string, frequency: string, start_date: string, end_date?: ?string, reverse_next_day?: bool, lines: list<array<string, mixed>>}  $data
     *
     * @throws LedgerPostingException when the lines do not balance
     */
    public function create(string $tenantId, array $data, string $actorId): RecurringJournal
    {
        $lines = $this->normalizedLines($data['lines']);

        if (! $this->postingService->validateBalance($lines)) {
            throw new LedgerPostingException('The lines of a recurring journal must balance with positive debit/credit values.');
        }

        $this->manualJournals->assertManualLines($tenantId, $lines);

        return RecurringJournal::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'description' => $data['description'],
            'frequency' => $data['frequency'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'next_run_date' => $data['start_date'],
            'reverse_next_day' => (bool) ($data['reverse_next_day'] ?? false),
            'status' => 'active',
            'lines' => $lines,
            'created_by' => $actorId,
        ]);
    }

    /**
     * Pauses, resumes or ends a template. An ended template is never run again.
     *
     * @throws LedgerConflictException when the template has already ended
     */
    public function changeStatus(RecurringJournal $recurring, string $status): RecurringJournal
    {
        return DB::transaction(function () use ($recurring, $status): RecurringJournal {
            $locked = RecurringJournal::whereKey($recurring->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'ended') {
                throw new LedgerConflictException('An ended recurring journal cannot change status.');
            }

            $locked->update(['status' => $status]);

            return $locked;
        });
    }

    /**
     * Posts the next due run of a template, if it is due on or before the date. A run already posted for its date is
     * returned as it is.
     *
     * @throws LedgerConflictException when the template is not active or nothing is due
     */
    public function runNext(RecurringJournal $recurring, CarbonImmutable $asOf, ?string $actorId): JournalEntry
    {
        return DB::transaction(function () use ($recurring, $asOf, $actorId): JournalEntry {
            $locked = RecurringJournal::whereKey($recurring->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'active') {
                throw new LedgerConflictException("Recurring journal [{$locked->name}] is {$locked->status}.");
            }

            $runDate = CarbonImmutable::parse($locked->next_run_date);

            if ($runDate->greaterThan($asOf)) {
                throw new LedgerConflictException("Recurring journal [{$locked->name}] is next due on {$runDate->toDateString()}.");
            }

            $existing = RecurringJournalRun::where('recurring_journal_id', $locked->id)->whereDate('run_date', $runDate->toDateString())->first();
            $entry = $existing !== null
                ? JournalEntry::findOrFail($existing->journal_entry_id)
                : $this->post($locked, $runDate, $actorId);

            $this->advance($locked, $runDate);

            return $entry;
        });
    }

    /**
     * Posts every run due on or before the date for all active templates of the tenant. A failing template (closed
     * period, inactive account) is reported and the others go on.
     *
     * @return array{posted: list<string>, failed: list<array{recurring_journal_id: string, message: string}>}
     */
    public function runDue(string $tenantId, CarbonImmutable $asOf, ?string $actorId): array
    {
        $posted = [];
        $failed = [];

        $templates = RecurringJournal::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereDate('next_run_date', '<=', $asOf->toDateString())
            ->orderBy('next_run_date')
            ->get();

        foreach ($templates as $template) {
            try {
                while ($template->status === 'active' && CarbonImmutable::parse($template->next_run_date)->lessThanOrEqualTo($asOf)) {
                    $posted[] = (string) $this->runNext($template, $asOf, $actorId)->id;
                    $template->refresh();
                }
            } catch (Throwable $exception) {
                $failed[] = ['recurring_journal_id' => (string) $template->id, 'message' => $exception->getMessage()];
            }
        }

        return ['posted' => $posted, 'failed' => $failed];
    }

    private function post(RecurringJournal $recurring, CarbonImmutable $runDate, ?string $actorId): JournalEntry
    {
        $tenantId = (string) $recurring->tenant_id;
        $period = $this->periods->forDate($tenantId, $runDate);

        $entry = $this->manualJournals->create([
            'tenant_id' => $tenantId,
            'fiscal_period_id' => (string) $period->id,
            'posting_date' => $runDate->toDateString(),
            'document_date' => $runDate->toDateString(),
            'journal_type' => 'recurring',
            'description' => "{$recurring->description} ({$runDate->toDateString()})",
            'source_document_type' => 'gl_recurring_journal',
            'source_document_id' => (string) $recurring->id,
            'auto_reverse_on' => $recurring->reverse_next_day ? $runDate->addDay()->toDateString() : null,
            'recurring_journal_id' => (string) $recurring->id,
            'status' => 'posted',
            'lines' => $recurring->lines,
        ], $actorId);

        RecurringJournalRun::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'recurring_journal_id' => $recurring->id,
            'run_date' => $runDate->toDateString(),
            'journal_entry_id' => $entry->id,
        ]);

        return $entry;
    }

    /**
     * Moves the template to its next due date, counted from the start date so that month ends do not drift, and ends
     * it after its end date.
     */
    private function advance(RecurringJournal $recurring, CarbonImmutable $runDate): void
    {
        $start = CarbonImmutable::parse($recurring->start_date);
        $runs = RecurringJournalRun::where('recurring_journal_id', $recurring->id)->count();
        $next = $start->addMonthsNoOverflow($runs * $recurring->monthsBetweenRuns());

        if ($next->lessThanOrEqualTo($runDate)) {
            $next = $runDate->addMonthsNoOverflow($recurring->monthsBetweenRuns());
        }

        $ended = $recurring->end_date !== null && $next->greaterThan(CarbonImmutable::parse($recurring->end_date));

        $recurring->update([
            'last_run_date' => $runDate->toDateString(),
            'next_run_date' => $next->toDateString(),
            'status' => $ended ? 'ended' : $recurring->status,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{account_id: string, debit_amount: string, credit_amount: string, description: ?string, cost_center_id: ?string, profit_center_id: ?string}>
     */
    private function normalizedLines(array $lines): array
    {
        return array_map(static fn (array $line): array => [
            'account_id' => (string) $line['account_id'],
            'debit_amount' => Decimal::normalize((string) ($line['debit_amount'] ?? '0')),
            'credit_amount' => Decimal::normalize((string) ($line['credit_amount'] ?? '0')),
            'description' => isset($line['description']) ? (string) $line['description'] : null,
            'cost_center_id' => isset($line['cost_center_id']) ? (string) $line['cost_center_id'] : null,
            'profit_center_id' => isset($line['profit_center_id']) ? (string) $line['profit_center_id'] : null,
        ], $lines);
    }
}
