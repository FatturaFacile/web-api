<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Queries;

final readonly class GetExpenseReportDetailQuery
{
    public function __construct(public string $reportId) {}
}
