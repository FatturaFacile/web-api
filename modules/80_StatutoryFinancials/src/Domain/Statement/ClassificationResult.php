<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Domain\Statement;

/**
 * The ledger balances placed on the leaves of the statements (exact amounts, four decimals).
 */
final readonly class ClassificationResult
{
    /**
     * @param  array<string, string>  $balanceLeaves  Balance sheet leaf amounts, in the natural side of each line.
     * @param  array<string, string>  $incomeLeaves  Income statement leaf amounts (total cost layout for Switzerland).
     * @param  array<string, list<array{account_code: string, account_name: string, amount: string, line: string}>>  $sources
     * @param  list<array{account_code: string, account_name: string, balance: string, line: string, side: string}>  $bifront  Variable-sign accounts and the side they were placed on.
     * @param  list<array{account_code: string, account_name: string, line: string}>  $unmapped
     */
    public function __construct(
        public array $balanceLeaves,
        public array $incomeLeaves,
        public array $sources,
        public array $bifront,
        public array $unmapped,
    ) {}
}
