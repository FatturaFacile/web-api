<?php

declare(strict_types=1);

namespace Modules\Rebates\Contracts;

/**
 * Books the rebate accruals and settlements in the general ledger. The accounts are chosen by the agreement: the module
 * assumes no chart of accounts.
 */
interface RebateLedgerPosterInterface
{
    /**
     * Books the accrual of a supplier rebate: Dr accrued income (Ratei attivi), Cr supplier bonus income. A negative
     * amount (credit notes lowered the cumulative rebate) books the opposite entry.
     *
     * @return string the id of the journal entry
     */
    public function postAccrual(string $tenantId, string $date, string $amount, string $accrualAccountId, string $incomeAccountId, string $sourceId, string $description, ?string $userId): string;

    /**
     * Reverses a journal entry posted by this module.
     *
     * @return string the id of the reversal entry
     */
    public function reverse(string $journalEntryId, string $date, string $reason, ?string $userId): string;

    /**
     * Books the set-off of a settled rebate against a supplier payable: Dr payables (gross), Cr accrued income (net),
     * Cr VAT account (tax, when there is VAT).
     *
     * @return string the id of the journal entry
     */
    public function postSetOff(string $tenantId, string $date, string $net, string $tax, string $payablesAccountId, string $accrualAccountId, ?string $vatAccountId, string $sourceId, string $description, ?string $userId): string;
}
