<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Events;

/**
 * An expense report was rejected: listeners may notify the employee with the reason.
 */
final readonly class ExpenseReportRejectedEvent
{
    public function __construct(
        public string $reportId,
        public string $reportNumber,
        public string $userId,
        public string $reason,
        public string $tenantId,
    ) {}
}
