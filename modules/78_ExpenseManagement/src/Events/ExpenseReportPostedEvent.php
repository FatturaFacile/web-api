<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Events;

/**
 * An approved report was paid out: the General Ledger posts the journal entry. The lines balance (debits = credits).
 */
final readonly class ExpenseReportPostedEvent
{
    /**
     * @param  list<array{account_code: string, debit: string, credit: string, description: string}>  $journalLines
     */
    public function __construct(
        public string $reportId,
        public string $reportNumber,
        public string $currency,
        public string $postingDate,
        public array $journalLines,
        public string $tenantId,
        public ?string $companyId,
    ) {}
}
