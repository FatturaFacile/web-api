<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Application\Commands;

use Modules\LeaveManagement\Models\EmployeeLeaveAccrualLedger;
use Modules\LeaveManagement\Services\LeaveAccrualService;

final readonly class CalculateMonthlyLeaveAccrualsHandler
{
    public function __construct(
        private LeaveAccrualService $accrualService,
    ) {}

    /**
     * @return list<EmployeeLeaveAccrualLedger>
     */
    public function handle(CalculateMonthlyLeaveAccrualsCommand $command): array
    {
        return $this->accrualService->calculateMonthlyAccruals(
            tenantId: $command->tenantId,
            fiscalYear: $command->fiscalYear,
            month: $command->month,
            employeeId: $command->employeeId,
            countryFilter: $command->country,
        );
    }
}
