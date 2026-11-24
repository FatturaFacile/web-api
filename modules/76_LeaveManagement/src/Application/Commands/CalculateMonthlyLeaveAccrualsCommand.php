<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Application\Commands;

final readonly class CalculateMonthlyLeaveAccrualsCommand
{
    public function __construct(
        public string $tenantId,
        public int $fiscalYear,
        public int $month,
        public ?string $employeeId = null,
        public ?string $country = null,
    ) {}
}
