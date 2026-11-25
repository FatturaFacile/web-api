<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Queries;

final readonly class GetExpenseReportsQuery
{
    /**
     * @param  string|null  $restrictToUserId  set for members who see only their own reports
     */
    public function __construct(
        public ?string $status = null,
        public ?string $employeeId = null,
        public ?string $periodFrom = null,
        public ?string $periodTo = null,
        public ?string $search = null,
        public ?string $restrictToUserId = null,
        public int $perPage = 25,
    ) {}
}
