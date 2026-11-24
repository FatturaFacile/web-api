<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Application\Queries;

final readonly class GetDepartmentAbsenceGanttQuery
{
    public function __construct(
        public string $tenantId,
        public string $fromDate,
        public string $toDate,
        public ?string $departmentId = null,
        public string $country = 'CH',
        public ?string $cantonOrProvince = null,
    ) {}
}
