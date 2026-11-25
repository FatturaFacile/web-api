<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Services;

use Illuminate\Support\Str;
use Modules\GdoTradeTerms\Contracts\GdoLedgerPosterInterface;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;

/**
 * Posts the GDO trade terms entries through the double-entry posting contract of the general ledger.
 */
final readonly class GdoLedgerPoster implements GdoLedgerPosterInterface
{
    public function __construct(private DoubleEntryPostingServiceInterface $posting, private FiscalPeriodResolver $periods) {}

    public function post(string $tenantId, string $date, string $kind, string $sourceId, string $description, array $lines, ?string $userId): string
    {
        $journal = $this->posting->createAndPost([
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'fiscal_period_id' => $this->periods->forDate($tenantId, $date)->id,
            'posting_date' => $date,
            'document_date' => $date,
            'entry_number' => 'GDT-'.Str::upper(substr($kind, 0, 3)).'-'.Str::upper(substr($sourceId, -12)),
            'source_document_type' => 'gdo_'.$kind,
            'source_document_id' => $sourceId,
            'journal_type' => 'standard',
            'description' => $description,
            'lines' => array_map(static fn (array $line): array => [
                'account_id' => $line['account_id'],
                'debit_amount' => (float) $line['debit'],
                'credit_amount' => (float) $line['credit'],
                'description' => $line['description'],
            ], $lines),
        ], $userId);

        return (string) $journal->id;
    }

    public function reverse(string $journalEntryId, string $date, string $reason, ?string $userId): string
    {
        return (string) $this->posting->reverseJournal($journalEntryId, $date, $reason, $userId)->id;
    }
}
