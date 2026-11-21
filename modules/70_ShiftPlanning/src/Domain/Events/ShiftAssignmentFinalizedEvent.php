<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Events;

/**
 * A shift of a published roster got its final employee after publication: an open shift claimed or a swap approved.
 */
final readonly class ShiftAssignmentFinalizedEvent
{
    /**
     * @param  array<string, mixed>  $surchargeBreakdown
     */
    public function __construct(
        public string $tenantId,
        public string $assignmentId,
        public string $periodId,
        public string $employeeId,
        public string $startAt,
        public string $endAt,
        public string $estimatedLaborCost,
        public array $surchargeBreakdown,
    ) {}
}
