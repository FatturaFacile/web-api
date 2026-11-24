<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs;

final readonly class SubmitLeaveRequestDTO
{
    public function __construct(
        public string $tenantId,
        public string $employeeId,
        public string $leaveTypeId,
        public string $startDate,
        public string $endDate,
        public float $totalHours,
        public ?string $reason = null,
        public ?string $medicalCertificateRef = null
    ) {}
}
