<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Queries;

use Modules\ExpenseManagement\Services\BalanceService;

final class GetEmployeeExpenseBalanceHandler
{
    public function __construct(private readonly BalanceService $balances) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetEmployeeExpenseBalanceQuery $query): array
    {
        return $this->balances->forUser($query->userId, $query->currency);
    }
}
