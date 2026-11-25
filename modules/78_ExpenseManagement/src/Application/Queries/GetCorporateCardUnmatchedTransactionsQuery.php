<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Queries;

final readonly class GetCorporateCardUnmatchedTransactionsQuery
{
    /**
     * @param  string|null  $status  a reconciliation status; unmatched when empty
     */
    public function __construct(
        public ?string $status = 'unmatched',
        public ?string $cardAccountId = null,
        public int $perPage = 50,
    ) {}
}
