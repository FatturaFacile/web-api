<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs;

final readonly class TransferEmployeeDTO
{
    public function __construct(
        public string $employeeId,
        public ?string $toDepartmentId = null,
        public ?string $toTeamId = null,
        public ?string $toBusinessUnitId = null,
        public ?string $toCostCenterId = null,
        public ?string $toPositionId = null,
        public ?string $toManagerId = null,
        public ?string $effectiveDate = null,
        public string $reason = 'Organizational reassignment'
    ) {}
}
