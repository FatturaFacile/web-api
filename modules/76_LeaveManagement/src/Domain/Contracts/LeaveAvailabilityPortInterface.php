<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Domain\Contracts;

/**
 * Integration contract for Shift Planning (Mod 073) & Resource Availability (Mod 079).
 */
interface LeaveAvailabilityPortInterface
{
    /**
     * Return approved/pending absence windows for employees in a date range.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, list<array{
     *     leave_request_id: string,
     *     leave_type: string,
     *     status: string,
     *     start_date: string,
     *     end_date: string,
     *     requested_days: float,
     *     requested_hours: float
     * }>>
     */
    public function getAbsenceSpans(string $tenantId, array $employeeIds, string $fromDate, string $toDate, bool $approvedOnly = true): array;
}
