<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Queries;

/**
 * The colleagues that could take an own shift without breaking a blocking rule, with their upcoming shifts to offer in
 * exchange (the first step of the swap wizard).
 */
final readonly class ListEligibleSwapPeersQuery
{
    public function __construct(
        public string $tenantId,
        public string $employeeId,
        public string $assignmentId,
    ) {}
}
