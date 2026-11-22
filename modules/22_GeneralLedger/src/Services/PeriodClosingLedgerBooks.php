<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Enums\PostingAccountRole;
use Modules\GeneralLedger\Exceptions\LedgerConflictException;
use Modules\GeneralLedger\Exceptions\LedgerPostingException;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;

/**
 * Year-end closing and opening of the parallel ledger books (02 tax, 03 ifrs). The statutory book (01) is closed and
 * opened by PeriodClosingService, which also marks the fiscal year closed; the parallel books follow the same
 * entries (income statement to retained earnings, balance sheet carried forward) in their own book, each step
 * idempotent: a repeated call returns the entry already posted.
 */
final readonly class PeriodClosingLedgerBooks
{
    public const string ClosingSource = 'year_end_closing_';

    public const string OpeningSource = 'opening_balance_';

    public function __construct(
        private DoubleEntryPostingServiceInterface $postingService,
        private LedgerBalanceReader $balances,
        private PostingAccountResolver $postingAccounts,
    ) {}

    /**
     * Net result of the income statement accounts of a year in a book: positive for a profit (utile), negative for a
     * loss (perdita). Nothing is posted.
     */
    public function netResult(FiscalYear $fy, string $ledgerType): string
    {
        return $this->resultLines($fy, $ledgerType)[1];
    }

    /**
     * Posts the closing entry of the book: revenue and expense accounts to zero against retained earnings.
     *
     * @throws LedgerConflictException when a period of the year is still open
     */
    public function close(FiscalYear $fy, string $ledgerType, string $actorId): ?JournalEntry
    {
        return DB::transaction(function () use ($fy, $ledgerType, $actorId): ?JournalEntry {
            $fy = FiscalYear::where('id', $fy->id)->lockForUpdate()->firstOrFail();
            $existing = $this->existing($fy, self::ClosingSource.$ledgerType, $ledgerType);

            if ($existing !== null) {
                return $existing;
            }

            $openPeriods = FiscalPeriod::where('fiscal_year_id', $fy->id)->where('period_number', '<=', 12)->where('status', '!=', 'closed')->count();

            if ($openPeriods > 0) {
                throw new LedgerConflictException("Cannot close fiscal year [{$fy->year_code}]: {$openPeriods} fiscal periods are still open.");
            }

            [$lines] = $this->resultLines($fy, $ledgerType);

            if ($lines === []) {
                return null;
            }

            $closingPeriod = FiscalPeriod::where('fiscal_year_id', $fy->id)->where('period_number', 13)->first()
                ?? FiscalPeriod::where('fiscal_year_id', $fy->id)->where('period_number', 12)->firstOrFail();

            return $this->postingService->postYearEndClosing([
                'tenant_id' => $fy->tenant_id,
                'company_id' => $fy->company_id,
                'fiscal_period_id' => $closingPeriod->id,
                'entry_number' => 'YE-CLOSE-'.$fy->year_code.'-'.strtoupper($ledgerType),
                'ledger_type' => $ledgerType,
                'posting_date' => $fy->end_date,
                'document_date' => $fy->end_date,
                'description' => "Year-end financial closing for {$fy->year_code} ({$ledgerType} ledger)",
                'source_document_type' => self::ClosingSource.$ledgerType,
                'source_document_id' => $fy->id,
                'lines' => $lines,
            ], $this->uuid($actorId));
        });
    }

    /**
     * Posts the opening entry of the next year of the book with the balance sheet balances of the closed year.
     */
    public function open(FiscalYear $fy, ?FiscalYear $nextYear, string $ledgerType, string $actorId): ?JournalEntry
    {
        return DB::transaction(function () use ($fy, $nextYear, $ledgerType, $actorId): ?JournalEntry {
            $fy = FiscalYear::where('id', $fy->id)->lockForUpdate()->firstOrFail();
            $existing = $this->existing($fy, self::OpeningSource.$ledgerType, $ledgerType);

            if ($existing !== null) {
                return $existing;
            }

            $lines = $this->openingLines($fy, $ledgerType);

            if ($nextYear === null || $lines === []) {
                return null;
            }

            $firstPeriod = FiscalPeriod::where('fiscal_year_id', $nextYear->id)->orderBy('start_date')->orderBy('period_number')->firstOrFail();
            $start = CarbonImmutable::parse($nextYear->start_date)->toDateString();

            return $this->postingService->createAndPost([
                'tenant_id' => $fy->tenant_id,
                'company_id' => $fy->company_id,
                'fiscal_period_id' => $firstPeriod->id,
                'entry_number' => 'OPEN-'.$nextYear->year_code.'-'.strtoupper($ledgerType),
                'ledger_type' => $ledgerType,
                'journal_type' => 'opening',
                'posting_date' => $start,
                'document_date' => $start,
                'description' => "Opening balances carried forward from {$fy->year_code} ({$ledgerType} ledger)",
                'source_document_type' => self::OpeningSource.$ledgerType,
                'source_document_id' => $fy->id,
                'bank_balance_managed' => true,
                'lines' => $lines,
            ], $this->uuid($actorId));
        });
    }

    private function existing(FiscalYear $fy, string $source, string $ledgerType): ?JournalEntry
    {
        return JournalEntry::where('tenant_id', $fy->tenant_id)
            ->where('ledger_type', $ledgerType)
            ->where('source_document_type', $source)
            ->where('source_document_id', $fy->id)
            ->where('status', 'posted')
            ->first();
    }

    private function uuid(string $actorId): string
    {
        return Str::isUuid($actorId) ? $actorId : (string) Str::uuid7();
    }

    /**
     * @return array{0: list<array{account_id: string, debit_amount: string, credit_amount: string, description: string}>, 1: string}
     *
     * @throws LedgerPostingException when the year has a result and no retained earnings account is configured
     */
    private function resultLines(FiscalYear $fy, string $ledgerType): array
    {
        $movements = $this->balances->movements((string) $fy->tenant_id, CarbonImmutable::parse($fy->start_date), CarbonImmutable::parse($fy->end_date), $ledgerType)
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

    /**
     * @return list<array{account_id: string, debit_amount: string, credit_amount: string, description: string}>
     */
    private function openingLines(FiscalYear $fy, string $ledgerType): array
    {
        $lines = [];

        foreach ($this->balances->closedBalances((string) $fy->tenant_id, CarbonImmutable::parse($fy->end_date), $ledgerType) as $account) {
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
