<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Models\Accrual;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Accruals and deferrals (ratei e risconti) at a cut-off date, computed by days of coverage:
 *
 * - accrued_revenue (rateo attivo): the share up to the cut-off of a revenue collected later; Dr accrual asset, Cr revenue;
 * - accrued_expense (rateo passivo): the share up to the cut-off of a cost paid later; Dr expense, Cr accrual liability;
 * - prepaid_expense (risconto attivo): the share after the cut-off of a cost already booked; Dr deferral asset, Cr expense;
 * - deferred_revenue (risconto passivo): the share after the cut-off of a revenue already booked; Dr revenue, Cr deferral liability.
 *
 * The entry is an adjusting manual journal dated on the cut-off date, subject to the approval policy, and carries the
 * day after as its automatic reversal date (see ScheduledReversalService). Days are counted inclusively; the share is
 * total × days / coverage days, rounded half up to the cent.
 */
final readonly class AccrualService
{
    public function __construct(
        private ManualJournalService $manualJournals,
        private FiscalPeriodResolver $periods,
    ) {}

    /**
     * Computes and posts an accrual or deferral.
     *
     * @param  array{accrual_type: string, description: string, total_amount: string|int|float, coverage_start: string, coverage_end: string, cutoff_date: string, income_statement_account_id: string, balance_sheet_account_id: string, cost_center_id?: ?string}  $data
     *
     * @throws ValidationException when the accounts do not fit the type or the cut-off is outside the coverage
     */
    public function create(string $tenantId, array $data, string $actorId): Accrual
    {
        $type = $data['accrual_type'];
        $start = CarbonImmutable::parse($data['coverage_start'])->startOfDay();
        $end = CarbonImmutable::parse($data['coverage_end'])->startOfDay();
        $cutoff = CarbonImmutable::parse($data['cutoff_date'])->startOfDay();

        if ($cutoff->lessThan($start) || $cutoff->greaterThanOrEqualTo($end)) {
            throw ValidationException::withMessages(['cutoff_date' => ['The cut-off date must fall within the coverage, before its last day.']]);
        }

        [$incomeAccount, $balanceAccount] = $this->accounts($tenantId, $type, $data['income_statement_account_id'], $data['balance_sheet_account_id']);
        $total = Decimal::normalize((string) $data['total_amount']);
        $coverageDays = (int) $start->diffInDays($end) + 1;
        $accruedDays = in_array($type, ['accrued_revenue', 'accrued_expense'], true)
            ? (int) $start->diffInDays($cutoff) + 1
            : (int) $cutoff->diffInDays($end);
        $amount = Decimal::round2(bcdiv(bcmul($total, (string) $accruedDays, 8), (string) $coverageDays, 8));

        if (! Decimal::isPositive($amount)) {
            throw ValidationException::withMessages(['total_amount' => ['The share at the cut-off date is zero: nothing to post.']]);
        }

        return DB::transaction(function () use ($tenantId, $data, $actorId, $type, $start, $end, $cutoff, $incomeAccount, $balanceAccount, $total, $coverageDays, $accruedDays, $amount): Accrual {
            $accrual = Accrual::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'accrual_type' => $type,
                'description' => $data['description'],
                'total_amount' => $total,
                'coverage_start' => $start->toDateString(),
                'coverage_end' => $end->toDateString(),
                'cutoff_date' => $cutoff->toDateString(),
                'coverage_days' => $coverageDays,
                'accrued_days' => $accruedDays,
                'amount' => $amount,
                'income_statement_account_id' => $incomeAccount->id,
                'balance_sheet_account_id' => $balanceAccount->id,
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'reversal_date' => $cutoff->addDay()->toDateString(),
                'status' => 'posted',
                'created_by' => $actorId,
            ]);

            $incomeLine = ['account_id' => (string) $incomeAccount->id, 'cost_center_id' => $data['cost_center_id'] ?? null, 'description' => $data['description']];
            $balanceLine = ['account_id' => (string) $balanceAccount->id, 'description' => $data['description']];
            $debitIncome = in_array($type, ['accrued_expense', 'deferred_revenue'], true);
            $lines = $debitIncome
                ? [$incomeLine + ['debit_amount' => $amount, 'credit_amount' => '0'], $balanceLine + ['debit_amount' => '0', 'credit_amount' => $amount]]
                : [$balanceLine + ['debit_amount' => $amount, 'credit_amount' => '0'], $incomeLine + ['debit_amount' => '0', 'credit_amount' => $amount]];

            $entry = $this->manualJournals->create([
                'tenant_id' => $tenantId,
                'fiscal_period_id' => (string) $this->periods->forDate($tenantId, $cutoff)->id,
                'posting_date' => $cutoff->toDateString(),
                'document_date' => $cutoff->toDateString(),
                'journal_type' => 'adjusting',
                'description' => "{$data['description']} ({$type} at {$cutoff->toDateString()})",
                'source_document_type' => 'gl_accrual',
                'source_document_id' => (string) $accrual->id,
                'auto_reverse_on' => $cutoff->addDay()->toDateString(),
                'status' => 'posted',
                'lines' => $lines,
            ], $actorId);

            $accrual->update(['journal_entry_id' => $entry->id]);

            return $accrual->refresh()->load('journalEntry');
        });
    }

    /**
     * @return array{0: ChartOfAccount, 1: ChartOfAccount}
     *
     * @throws ValidationException
     */
    private function accounts(string $tenantId, string $type, string $incomeAccountId, string $balanceAccountId): array
    {
        $income = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($incomeAccountId)->first();
        $balance = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($balanceAccountId)->first();
        $incomeTypes = str_ends_with($type, '_revenue') ? ['revenue'] : ['expense', 'cogs'];
        $balanceTypes = in_array($type, ['accrued_revenue', 'prepaid_expense'], true) ? ['asset'] : ['liability'];
        $errors = [];

        if ($income === null || ! in_array($income->account_type, $incomeTypes, true)) {
            $errors['income_statement_account_id'] = ['For '.$type.' the income statement account must be of type '.implode(' or ', $incomeTypes).'.'];
        }

        if ($balance === null || ! in_array($balance->account_type, $balanceTypes, true)) {
            $errors['balance_sheet_account_id'] = ['For '.$type.' the balance sheet account must be of type '.implode(' or ', $balanceTypes).'.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        /** @var ChartOfAccount $income */
        /** @var ChartOfAccount $balance */
        return [$income, $balance];
    }
}
