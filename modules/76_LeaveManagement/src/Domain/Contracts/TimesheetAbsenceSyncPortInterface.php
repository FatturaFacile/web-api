<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Domain\Contracts;

/**
 * Integration contract for Monthly Timesheets / Cartellini (Mod 072).
 */
interface TimesheetAbsenceSyncPortInterface
{
    /**
     * Return daily absence justification entries for an employee over a calendar month.
     *
     * @return list<array{
     *     date: string,
     *     leave_request_id: string,
     *     leave_type: string,
     *     hours: float,
     *     status: string
     * }>
     */
    public function getMonthlyEmployeeAbsenceDays(string $tenantId, string $employeeId, int $year, int $month): array;
}
