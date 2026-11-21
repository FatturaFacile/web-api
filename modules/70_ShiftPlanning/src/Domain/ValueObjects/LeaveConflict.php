<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * A leave of an employee that overlaps the shift (Mod 074).
 */
final readonly class LeaveConflict
{
    public function __construct(
        public string $leaveRequestId,
        public bool $isApproved,
        public CarbonImmutable $startDate,
        public CarbonImmutable $endDate,
    ) {}
}
