<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services\Receivables;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Enums\PostingAccountRole;
use Modules\GeneralLedger\Exceptions\LedgerPostingException;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\ManualJournalService;
use Modules\GeneralLedger\Services\PostingAccountResolver;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\ReceivableProvisionPosting;

/**
 * Books the prudential bad debt provision (accantonamento al fondo svalutazione crediti) to the general ledger: debit
 * the bad debt expense account, credit the provision account (contra asset of the receivables). Only the difference
 * between the provision required at the reference date and what the earlier postings already booked is posted, so the
 * provision account always shows the required provision, a rerun books nothing and a lower requirement releases the
 * excess with the opposite entry.
 */
final class BadDebtProvisionPostingService
{
    public function __construct(
        private readonly BadDebtProvisionService $provision,
        private readonly PostingAccountResolver $accounts,
        private readonly ManualJournalService $journals,
        private readonly FiscalPeriodResolver $periods,
    ) {}

    /**
     * @return array{posting: ReceivableProvisionPosting|null, posted: bool, total_provision: string, previous_provision: string, adjustment_amount: string}
     *
     * @throws ValidationException when the date lies in no open fiscal period or the accounts are not configured
     */
    public function post(string $tenantId, CarbonInterface $asOf, ?string $currency, ?string $actorId): array
    {
        $calculation = $this->provision->calculate($tenantId, $asOf, null, $currency);

        try {
            $expense = $this->accounts->require($tenantId, PostingAccountRole::BadDebtExpense);
            $contra = $this->accounts->require($tenantId, PostingAccountRole::BadDebtProvision);
            $periodId = (string) $this->periods->forDate($tenantId, $asOf)->id;
        } catch (LedgerPostingException $exception) {
            throw ValidationException::withMessages(['accounts' => $exception->getMessage()]);
        }

        return DB::transaction(function () use ($tenantId, $asOf, $calculation, $expense, $contra, $periodId, $actorId): array {
            $currencyCode = $calculation['currency'];
            $previous = $this->booked($tenantId, $currencyCode);
            $total = $calculation['total_provision'];
            $adjustment = Decimal::round2(Decimal::sub($total, $previous));

            if (Decimal::isZero($adjustment)) {
                return ['posting' => null, 'posted' => false, 'total_provision' => $total, 'previous_provision' => $previous, 'adjustment_amount' => $adjustment];
            }

            $amount = Decimal::abs($adjustment);
            $increase = Decimal::isPositive($adjustment);
            $description = __($increase ? 'receivables.provision_posting_increase' : 'receivables.provision_posting_release', ['date' => $asOf->toDateString()]);
            $expenseLine = ['account_id' => (string) $expense->id, 'description' => $description];
            $contraLine = ['account_id' => (string) $contra->id, 'description' => $description];

            $posting = ReceivableProvisionPosting::query()->create([
                'tenant_id' => $tenantId,
                'as_of' => $asOf->toDateString(),
                'currency' => $currencyCode,
                'total_provision' => $total,
                'previous_provision' => $previous,
                'adjustment_amount' => $adjustment,
                'breakdown' => $calculation['lines'],
                'created_by' => $actorId,
            ]);

            $entry = $this->journals->create([
                'tenant_id' => $tenantId,
                'fiscal_period_id' => $periodId,
                'posting_date' => $asOf->toDateString(),
                'document_date' => $asOf->toDateString(),
                'journal_type' => 'adjusting',
                'description' => $description,
                'source_document_type' => 'receivable_provision',
                'source_document_id' => (string) $posting->id,
                'status' => 'posted',
                'lines' => $increase
                    ? [$expenseLine + ['debit_amount' => $amount, 'credit_amount' => '0'], $contraLine + ['debit_amount' => '0', 'credit_amount' => $amount]]
                    : [$contraLine + ['debit_amount' => $amount, 'credit_amount' => '0'], $expenseLine + ['debit_amount' => '0', 'credit_amount' => $amount]],
            ], $actorId);

            $posting->update(['journal_entry_id' => (string) $entry->id]);

            return ['posting' => $posting->refresh(), 'posted' => true, 'total_provision' => $total, 'previous_provision' => $previous, 'adjustment_amount' => $adjustment];
        });
    }

    /**
     * The postings of the company, newest first.
     *
     * @return list<ReceivableProvisionPosting>
     */
    public function history(string $tenantId): array
    {
        return ReceivableProvisionPosting::query()->where('tenant_id', $tenantId)->orderByDesc('as_of')->orderByDesc('created_at')->limit(100)->get()->all();
    }

    /**
     * What the earlier postings of the currency booked in total.
     */
    private function booked(string $tenantId, string $currency): string
    {
        $sum = ReceivableProvisionPosting::query()
            ->where('tenant_id', $tenantId)
            ->where('currency', $currency)
            ->lockForUpdate()
            ->pluck('adjustment_amount');

        return Decimal::round2($sum->reduce(static fn (string $carry, mixed $amount): string => Decimal::add($carry, (string) $amount), '0'));
    }
}
