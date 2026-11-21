<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Contracts;

use Carbon\CarbonImmutable;
use Modules\ShiftPlanning\Domain\ValueObjects\LeaveConflict;

/**
 * Leave management (Mod 074).
 */
interface LeaveManagementPort
{
    /**
     * Approved and pending leave of the employee that touches any day from $from to $to (inclusive).
     *
     * @return list<LeaveConflict>
     */
    public function conflictsFor(string $employeeId, CarbonImmutable $from, CarbonImmutable $to): array;
}
