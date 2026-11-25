<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Queries;

final readonly class GetEmployeeExpenseBalanceQuery
{
    public function __construct(public string $userId, public string $currency) {}
}
