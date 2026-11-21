<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Queries;

use Carbon\CarbonImmutable;

/**
 * The published shifts of the colleagues who share a roster period with the employee in a date window (team calendar).
 */
final readonly class GetTeamShiftsQuery
{
    public function __construct(
        public string $tenantId,
        public string $employeeId,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}
}
