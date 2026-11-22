<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Queries;

use Carbon\CarbonImmutable;

/**
 * Employee self-service. Own mode: the published shifts of the employee between two dates. Open mode: the open shifts of
 * published rosters the employee could claim right now (skills, certificates, leave and rest rules all pass).
 */
final readonly class GetEmployeeShiftsQuery
{
    public function __construct(
        public string $tenantId,
        public string $employeeId,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public bool $openShifts = false,
    ) {}
}
