<?php

declare(strict_types=1);

namespace Modules\Rebates\Services;

use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Rebates\Contracts\RebateLedgerPosterInterface;

/**
 * Posts the rebate entries through the double-entry posting contract of the general ledger.
 */
final readonly class RebateLedgerPoster implements RebateLedgerPosterInterface
{
    public function __construct(private DoubleEntryPostingServiceInterface $posting, private FiscalPeriodResolver $periods) {}

    public function postAccrual(string $tenantId, string $date, string $amount, string $accrualAccountId, string $incomeAccountId, string $sourceId, string $description, ?string $userId): string
    {
        $value = (float) Decimal::abs($amount);
        $debitAccrued = ! Decimal::isNegative($amount);

        return $this->post($tenantId, $date, 'accrual', $sourceId, $description, [
            ['account_id' => $accrualAccountId, 'debit_amount' => $debitAccrued ? $value : 0.0, 'credit_amount' => $debitAccrued ? 0.0 : $value, 'description' => 'Accrued supplier rebate'],
            ['account_id' => $incomeAccountId, 'debit_amount' => $debitAccrued ? 0.0 : $value, 'credit_amount' => $debitAccrued ? $value : 0.0, 'description' => 'Supplier rebate income'],
        ], $userId);
    }

    public function reverse(string $journalEntryId, string $date, string $reason, ?string $userId): string
    {
        return (string) $this->posting->reverseJournal($journalEntryId, $date, $reason, $userId)->id;
    }

    public function postSetOff(string $tenantId, string $date, string $net, string $tax, string $payablesAccountId, string $accrualAccountId, ?string $vatAccountId, string $sourceId, string $description, ?string $userId): string
    {
        $hasTax = Decimal::isPositive($tax);
        $lines = [
            ['account_id' => $payablesAccountId, 'debit_amount' => (float) Decimal::add($net, $tax), 'credit_amount' => 0.0, 'description' => 'Supplier payable set off'],
            ['account_id' => $accrualAccountId, 'debit_amount' => 0.0, 'credit_amount' => (float) $net, 'description' => 'Accrued supplier rebate settled'],
        ];

        if ($hasTax) {
            $lines[] = ['account_id' => (string) $vatAccountId, 'debit_amount' => 0.0, 'credit_amount' => (float) $tax, 'description' => 'VAT on supplier rebate'];
        }

        return $this->post($tenantId, $date, 'set_off', $sourceId, $description, $lines, $userId);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function post(string $tenantId, string $date, string $kind, string $sourceId, string $description, array $lines, ?string $userId): string
    {
        $journal = $this->posting->createAndPost([
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'fiscal_period_id' => $this->periods->forDate($tenantId, $date)->id,
            'posting_date' => $date,
            'document_date' => $date,
            'entry_number' => 'REB-'.Str::upper(substr($kind, 0, 3)).'-'.Str::upper(substr($sourceId, -12)),
            'source_document_type' => 'rebate_'.$kind,
            'source_document_id' => $sourceId,
            'journal_type' => 'standard',
            'description' => $description,
            'lines' => $lines,
        ], $userId);

        return (string) $journal->id;
    }
}
