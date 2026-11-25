<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Commands;

final readonly class ReconcileCorporateCardTransactionCommand
{
    /**
     * @param  string  $action  match | unmatch | ignore | dispute
     * @param  list<array{expense_line_id: string, amount?: string|null}>  $matches  several entries split the transaction
     */
    public function __construct(
        public string $transactionId,
        public string $action,
        public array $matches,
        public string $userId,
    ) {}
}
