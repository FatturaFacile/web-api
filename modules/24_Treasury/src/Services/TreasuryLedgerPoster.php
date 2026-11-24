<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Exceptions\FiscalPeriodException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\Treasury\Exceptions\TreasuryOperationException;

/**
 * The single door of treasury and banking to the general ledger: entries go through the double-entry posting service,
 * in the fiscal period that contains their date (FiscalPeriodResolver), once per source document. A missing or closed
 * period refuses the operation with a 422 instead of booking the movement in another month.
 */
final readonly class TreasuryLedgerPoster
{
    public function __construct(
        private DoubleEntryPostingServiceInterface $postingService,
        private FiscalPeriodResolver $periods,
    ) {}

    /**
     * Posts a balanced entry, or returns the posted entry the source already has. A line in a foreign currency without
     * `exchange_rate` is converted into the accounting currency at the rate of the exchange-rate service on the date
     * (refused when there is none): the ledger never books a foreign amount at par.
     *
     * @param  list<array{account: ChartOfAccount, debit: string, credit: string, description: string, currency?: string, exchange_rate?: string}>  $lines
     * @param  bool  $bankBalanceManaged  The caller moves the balances of the treasury bank accounts itself: the posting
     *                                    must not announce the bank lines again.
     *
     * @throws TreasuryOperationException when no open fiscal period contains the date or the entry is refused
     */
    public function post(string $tenantId, string $sourceType, string $sourceId, string $date, string $documentNumber, string $description, array $lines, bool $bankBalanceManaged, ?string $actorUserId = null): JournalEntry
    {
        $existing = $this->postedEntry($tenantId, $sourceType, $sourceId);

        if ($existing !== null) {
            return $existing;
        }

        $day = CarbonImmutable::parse($date)->toDateString();

        try {
            $period = $this->periods->forDate($tenantId, $day);

            return $this->postingService->createAndPost([
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'fiscal_period_id' => $period->id,
                'entry_number' => 'TRS-'.strtoupper(Str::random(10)),
                'source_document_type' => $sourceType,
                'source_document_id' => $sourceId,
                'document_number' => mb_substr($documentNumber, 0, 100),
                'posting_date' => $day,
                'document_date' => $day,
                'journal_type' => 'standard',
                'description' => mb_substr($description, 0, 500),
                'bank_balance_managed' => $bankBalanceManaged,
                'lines' => array_map(static fn (array $line): array => [
                    'account_id' => $line['account']->id,
                    'debit_amount' => $line['debit'],
                    'credit_amount' => $line['credit'],
                    'currency' => $line['currency'] ?? null,
                    'exchange_rate' => $line['exchange_rate'] ?? null,
                    'description' => mb_substr($line['description'], 0, 255),
                ], $lines),
            ], $actorUserId);
        } catch (FiscalPeriodException $e) {
            throw new TreasuryOperationException(__('banking.errors.posting_period_unavailable', ['reason' => $e->getMessage()]), 'date');
        } catch (InvalidArgumentException $e) {
            if ($e instanceof TreasuryOperationException) {
                throw $e;
            }

            throw new TreasuryOperationException(__('banking.errors.posting_period_unavailable', ['reason' => $e->getMessage()]), 'date');
        }
    }

    /**
     * Reverses the posted entry of a source on the given date; nothing happens when the source has none.
     *
     * @throws TreasuryOperationException when the reversal date has no open period
     */
    public function reverse(string $tenantId, string $sourceType, string $sourceId, string $date, string $reason, bool $bankBalanceManaged): ?JournalEntry
    {
        $entry = $this->postedEntry($tenantId, $sourceType, $sourceId);

        if ($entry === null) {
            return null;
        }

        try {
            return $this->postingService->reverseJournal((string) $entry->id, CarbonImmutable::parse($date)->toDateString(), $reason, null, $bankBalanceManaged);
        } catch (InvalidArgumentException $e) {
            throw new TreasuryOperationException(__('banking.errors.posting_period_unavailable', ['reason' => $e->getMessage()]), 'date');
        }
    }

    /**
     * The posted entry of a source document that is still in force: neither reversed nor itself a reversal.
     */
    public function postedEntry(string $tenantId, string $sourceType, string $sourceId): ?JournalEntry
    {
        return JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('source_document_type', $sourceType)
            ->where('source_document_id', $sourceId)
            ->where('status', 'posted')
            ->where('journal_type', '!=', 'reversing')
            ->latest('created_at')
            ->first();
    }
}
