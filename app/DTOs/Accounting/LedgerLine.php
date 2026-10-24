<?php

declare(strict_types=1);

namespace App\DTOs\Accounting;

/**
 * One line of a general ledger entry. Amounts are integer cents, so entries always add up exactly.
 */
final readonly class LedgerLine
{
    public function __construct(
        public string $accountCode,
        public string $accountName,
        public string $description,
        public int $debit,
        public int $credit,
        public ?string $vatCode = null,
    ) {}
}
