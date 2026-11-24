<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Application\Queries;

use Carbon\CarbonImmutable;
use Modules\LeaveManagement\Services\DepartmentStaffingService;

final readonly class GetDepartmentAbsenceGanttHandler
{
    public function __construct(
        private DepartmentStaffingService $staffingService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetDepartmentAbsenceGanttQuery $query): array
    {
        return $this->staffingService->buildDepartmentGantt(
            tenantId: $query->tenantId,
            from: CarbonImmutable::parse($query->fromDate)->startOfDay(),
            to: CarbonImmutable::parse($query->toDate)->startOfDay(),
            departmentId: $query->departmentId,
            country: $query->country,
            cantonOrProvince: $query->cantonOrProvince,
        );
    }
}
