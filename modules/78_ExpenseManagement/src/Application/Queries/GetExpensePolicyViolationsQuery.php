<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Queries;

final readonly class GetExpensePolicyViolationsQuery
{
    public function __construct(
        public ?string $reportId = null,
        public ?string $severity = null,
        public ?string $employeeId = null,
        public ?string $restrictToUserId = null,
        public int $page = 1,
        public int $perPage = 25,
    ) {}
}
