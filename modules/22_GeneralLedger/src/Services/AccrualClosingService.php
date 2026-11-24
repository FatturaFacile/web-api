<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\Accrual;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Throwable;

/**
 * Automates 1-click year-end closing adjustment entries (31/12) and new-year reversal entries (01/01)
 * for Mod 022 multi-period transitory items while enforcing strict idempotency and audit traceability.
 */
final readonly class AccrualClosingService
{
    public function __construct(
        private AccrualScheduleService $schedule,
        private ManualJournalService $manualJournals,
        private FiscalPeriodResolver $periods,
        private DoubleEntryPostingServiceInterface $postingService,
    ) {}

    /**
     * Generates balanced 31/12 (fiscal year-end) closing entries for selected or all pending transitory items.
     *
     * @param  array{
     *   fiscal_year_id?: ?string,
     *   year?: ?int,
     *   item_keys?: list<array{source_type: string, source_line_id: string}>|null,
     *   balance_sheet_accounts?: array<string, string>|null,
     *   auto_generate_reversals?: bool
     * }  $payload
     * @return array{
     *   cutoff_date: string,
     *   reversal_date: string,
     *   posted: list<array<string, mixed>>,
     *   skipped: list<array{item_key: string, reason: string}>,
     *   failed: list<array{item_key: string, message: string}>,
     *   reversals: array{reversed: list<array<string, mixed>>, skipped: list<array{accrual_id: string, reason: string}>, failed: list<array{accrual_id: string, message: string}>}
     * }
     */
    public function generateClosingEntries(string $tenantId, array $payload, string $actorId): array
    {
        $summary = $this->schedule->summary($tenantId, [
            'fiscal_year_id' => $payload['fiscal_year_id'] ?? null,
            'year' => $payload['year'] ?? null,
        ]);

        $cutoffDate = $summary['cutoff_date'];
        $reversalDate = $summary['reversal_date'];
        $fiscalYearId = $summary['fiscal_year']['id'] ?? null;
        $overrides = $payload['balance_sheet_accounts'] ?? [];

        $wantedKeys = null;
        if (! empty($payload['item_keys'])) {
            $wantedKeys = [];
            foreach ($payload['item_keys'] as $entry) {
                $wantedKeys[$entry['source_type'].':'.$entry['source_line_id']] = true;
            }
        }

        $posted = [];
        $skipped = [];
        $failed = [];
        $postedAccrualIds = [];

        foreach ($summary['items'] as $item) {
            $itemKey = (string) $item['item_key'];

            if ($wantedKeys !== null && ! isset($wantedKeys[$itemKey])) {
                continue;
            }

            if ($item['status'] !== 'calculated') {
                $skipped[] = ['item_key' => $itemKey, 'reason' => __('general_ledger_errors.accrual.already_posted')];

                continue;
            }

            $type = (string) $item['accrual_type'];
            $incomeAccountId = $item['income_statement_account_id'] ?? null;
            $balanceAccountId = $overrides[$type] ?? ($item['balance_sheet_account_id'] ?? null);

            if (empty($incomeAccountId)) {
                $failed[] = ['item_key' => $itemKey, 'message' => __('general_ledger_errors.accrual.missing_income_account', ['type' => $type])];

                continue;
            }

            if (empty($balanceAccountId)) {
                $failed[] = ['item_key' => $itemKey, 'message' => __('general_ledger_errors.accrual.missing_balance_sheet_account', ['type' => $type])];

                continue;
            }

            try {
                $created = DB::transaction(function () use ($tenantId, $item, $type, $incomeAccountId, $balanceAccountId, $cutoffDate, $reversalDate, $fiscalYearId, $actorId): ?Accrual {
                    $existing = Accrual::withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->where('source_type', (string) $item['source_type'])
                        ->where('source_line_id', (string) $item['source_line_id'])
                        ->whereDate('cutoff_date', $cutoffDate)
                        ->whereNull('deleted_at')
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        return null;
                    }

                    [$incomeAccount, $balanceAccount] = $this->validateAccounts($tenantId, $type, (string) $incomeAccountId, (string) $balanceAccountId);

                    $amount = Decimal::round2((string) $item['transitory_amount']);
                    if (! Decimal::isPositive($amount)) {
                        throw new \RuntimeException(__('general_ledger_errors.accrual.nothing_to_post'));
                    }

                    $accrual = Accrual::create([
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenantId,
                        'fiscal_year_id' => $fiscalYearId,
                        'accrual_type' => $type,
                        'source_type' => (string) $item['source_type'],
                        'source_document_type' => $item['source_document_type'],
                        'source_document_id' => $item['source_document_id'],
                        'source_line_id' => (string) $item['source_line_id'],
                        'source_document_number' => $item['source_document_number'],
                        'currency' => (string) $item['currency'],
                        'description' => (string) $item['description'],
                        'total_amount' => Decimal::normalize((string) $item['total_amount']),
                        'coverage_start' => (string) $item['competence_start_date'],
                        'coverage_end' => (string) $item['competence_end_date'],
                        'cutoff_date' => $cutoffDate,
                        'coverage_days' => (int) $item['total_days'],
                        'accrued_days' => (int) $item['transitory_days'],
                        'period_days' => (int) $item['period_days'],
                        'recognized_amount' => Decimal::normalize((string) $item['recognized_amount']),
                        'amount' => $amount,
                        'income_statement_account_id' => $incomeAccount->id,
                        'balance_sheet_account_id' => $balanceAccount->id,
                        'cost_center_id' => $item['cost_center_id'],
                        'reversal_date' => $reversalDate,
                        'status' => 'posted',
                        'created_by' => $actorId,
                    ]);

                    $lineDescription = (string) $item['description'];
                    $incomeLine = [
                        'account_id' => (string) $incomeAccount->id,
                        'cost_center_id' => $item['cost_center_id'],
                        'description' => $lineDescription,
                    ];
                    $balanceLine = [
                        'account_id' => (string) $balanceAccount->id,
                        'description' => $lineDescription,
                    ];

                    $debitIncome = in_array($type, ['accrued_expense', 'deferred_revenue'], true);
                    $lines = $debitIncome
                        ? [$incomeLine + ['debit_amount' => $amount, 'credit_amount' => '0'], $balanceLine + ['debit_amount' => '0', 'credit_amount' => $amount]]
                        : [$balanceLine + ['debit_amount' => $amount, 'credit_amount' => '0'], $incomeLine + ['debit_amount' => '0', 'credit_amount' => $amount]];

                    $cutoffCarbon = CarbonImmutable::parse($cutoffDate);
                    $entry = $this->manualJournals->create([
                        'tenant_id' => $tenantId,
                        'fiscal_period_id' => (string) $this->periods->forDate($tenantId, $cutoffCarbon)->id,
                        'posting_date' => $cutoffDate,
                        'document_date' => $cutoffDate,
                        'journal_type' => 'adjusting',
                        'description' => "{$lineDescription} ({$type} at {$cutoffDate})",
                        'source_document_type' => 'gl_accrual',
                        'source_document_id' => (string) $accrual->id,
                        'document_number' => $item['source_document_number'],
                        'auto_reverse_on' => $reversalDate,
                        'status' => 'posted',
                        'lines' => $lines,
                    ], $actorId);

                    $accrual->update(['journal_entry_id' => $entry->id]);

                    return $accrual->refresh()->load('journalEntry');
                });

                if ($created === null) {
                    $skipped[] = ['item_key' => $itemKey, 'reason' => __('general_ledger_errors.accrual.already_posted')];

                    continue;
                }

                $postedAccrualIds[] = (string) $created->id;
                $posted[] = [
                    'item_key' => $itemKey,
                    'accrual_id' => (string) $created->id,
                    'accrual_type' => $type,
                    'amount' => (string) $created->amount,
                    'journal_entry_id' => (string) $created->journal_entry_id,
                    'entry_number' => $created->journalEntry?->entry_number,
                    'status' => $created->journalEntry->status ?? 'posted',
                ];
            } catch (Throwable $exception) {
                $failed[] = ['item_key' => $itemKey, 'message' => $exception->getMessage()];
            }
        }

        $reversals = ['reversed' => [], 'skipped' => [], 'failed' => []];
        if (! empty($payload['auto_generate_reversals']) && $postedAccrualIds !== []) {
            $reversals = $this->generateReversals($tenantId, [
                'accrual_ids' => $postedAccrualIds,
                'reversal_date' => $reversalDate,
            ], $actorId);
        }

        return [
            'cutoff_date' => $cutoffDate,
            'reversal_date' => $reversalDate,
            'posted' => $posted,
            'skipped' => $skipped,
            'failed' => $failed,
            'reversals' => $reversals,
        ];
    }

    /**
     * Generates mirror 01/01 reversal entries for posted accrual closing entries.
     *
     * @param  array{fiscal_year_id?: ?string, year?: ?int, accrual_ids?: list<string>|null, reversal_date?: ?string}  $payload
     * @return array{
     *   reversed: list<array<string, mixed>>,
     *   skipped: list<array{accrual_id: string, reason: string}>,
     *   failed: list<array{accrual_id: string, message: string}>
     * }
     */
    public function generateReversals(string $tenantId, array $payload, string $actorId): array
    {
        $query = Accrual::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereNotNull('journal_entry_id')
            ->with('journalEntry');

        if (! empty($payload['accrual_ids'])) {
            $query->whereIn('id', $payload['accrual_ids']);
        } else {
            $window = $this->schedule->resolveFiscalWindow(
                $tenantId,
                isset($payload['fiscal_year_id']) ? (string) $payload['fiscal_year_id'] : null,
                isset($payload['year']) ? (int) $payload['year'] : null,
            );
            $query->whereDate('cutoff_date', '>=', $window['start']->toDateString())
                ->whereDate('cutoff_date', '<=', $window['end']->toDateString());
        }

        $accruals = $query->orderBy('cutoff_date')->get();
        $reversed = [];
        $skipped = [];
        $failed = [];
        $overrideDate = ! empty($payload['reversal_date']) ? CarbonImmutable::parse((string) $payload['reversal_date'])->toDateString() : null;

        foreach ($accruals as $accrual) {
            $entry = $accrual->journalEntry;

            if (! $entry instanceof JournalEntry) {
                $skipped[] = ['accrual_id' => (string) $accrual->id, 'reason' => 'No closing journal entry linked.'];

                continue;
            }

            if ($accrual->getAttribute('reversal_journal_entry_id') !== null || $entry->status === 'reversed') {
                $skipped[] = ['accrual_id' => (string) $accrual->id, 'reason' => 'Reversal already posted.'];

                continue;
            }

            if ($entry->status !== 'posted') {
                $skipped[] = ['accrual_id' => (string) $accrual->id, 'reason' => "Closing entry status [{$entry->status}] is not posted."];

                continue;
            }

            $reversalDate = $overrideDate ?? ($accrual->reversal_date?->toDateString() ?? CarbonImmutable::parse($accrual->cutoff_date)->addDay()->toDateString());

            try {
                $reversalEntry = DB::transaction(function () use ($accrual, $entry, $reversalDate, $actorId): JournalEntry {
                    $reversal = $this->postingService->reverseJournal(
                        (string) $entry->id,
                        $reversalDate,
                        "Opening reversal ({$accrual->accrual_type})",
                        $actorId,
                    );

                    $accrual->update([
                        'reversal_journal_entry_id' => (string) $reversal->id,
                        'status' => 'reversed',
                    ]);

                    return $reversal;
                });

                $reversed[] = [
                    'accrual_id' => (string) $accrual->id,
                    'closing_journal_entry_id' => (string) $entry->id,
                    'reversal_journal_entry_id' => (string) $reversalEntry->id,
                    'reversal_entry_number' => $reversalEntry->entry_number,
                    'reversal_date' => $reversalDate,
                ];
            } catch (Throwable $exception) {
                $failed[] = ['accrual_id' => (string) $accrual->id, 'message' => $exception->getMessage()];
            }
        }

        return [
            'reversed' => $reversed,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    /**
     * @return array{0: ChartOfAccount, 1: ChartOfAccount}
     */
    private function validateAccounts(string $tenantId, string $type, string $incomeAccountId, string $balanceAccountId): array
    {
        $income = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($incomeAccountId)->first();
        $balance = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($balanceAccountId)->first();

        $incomeTypes = str_ends_with($type, '_revenue') ? ['revenue'] : ['expense', 'cogs'];
        $balanceTypes = in_array($type, ['accrued_revenue', 'prepaid_expense'], true) ? ['asset'] : ['liability'];

        if ($income === null || ! in_array($income->account_type, $incomeTypes, true)) {
            throw new \InvalidArgumentException(__('general_ledger_errors.accrual.income_account_type', ['type' => $type, 'types' => implode(' / ', $incomeTypes)]));
        }

        if ($balance === null || ! in_array($balance->account_type, $balanceTypes, true)) {
            throw new \InvalidArgumentException(__('general_ledger_errors.accrual.balance_account_type', ['type' => $type, 'types' => implode(' / ', $balanceTypes)]));
        }

        return [$income, $balance];
    }
}
