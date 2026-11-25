<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Events;

/**
 * An expense report was approved: listeners may notify the employee.
 */
final readonly class ExpenseReportApprovedEvent
{
    public function __construct(
        public string $reportId,
        public string $reportNumber,
        public string $userId,
        public string $approvedAmount,
        public string $tenantId,
    ) {}
}
