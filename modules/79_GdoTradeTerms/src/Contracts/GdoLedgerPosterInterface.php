<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Contracts;

/**
 * Books the accruals, releases and set-offs of the GDO trade terms in the general ledger through the double-entry posting
 * contract. The accounts are chosen by the caller (the contract, or the configured chart codes): the module assumes no chart.
 */
interface GdoLedgerPosterInterface
{
    /**
     * Posts a balanced entry. Amounts are decimal strings; every line carries either a debit or a credit.
     *
     * @param  list<array{account_id: string, debit: string, credit: string, description: string}>  $lines
     * @return string the id of the journal entry
     */
    public function post(string $tenantId, string $date, string $kind, string $sourceId, string $description, array $lines, ?string $userId): string;

    /**
     * Reverses a journal entry posted by this module.
     *
     * @return string the id of the reversal entry
     */
    public function reverse(string $journalEntryId, string $date, string $reason, ?string $userId): string;
}
