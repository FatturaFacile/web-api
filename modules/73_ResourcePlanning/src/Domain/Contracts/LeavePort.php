<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Domain\Contracts;

use Carbon\CarbonImmutable;
use Modules\ResourcePlanning\Domain\ValueObjects\LeaveSpan;

/**
 * Approved leave, vacation and permissions (Mod 074). Rejected, cancelled and pending requests do not reduce capacity.
 */
interface LeavePort
{
    /**
     * @param  list<string>  $employeeIds
     * @return array<string, list<LeaveSpan>> keyed by employee id
     */
    public function approvedLeave(array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): array;
}
