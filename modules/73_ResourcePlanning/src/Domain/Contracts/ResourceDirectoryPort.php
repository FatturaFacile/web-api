<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Domain\Contracts;

use Carbon\CarbonImmutable;
use Modules\ResourcePlanning\Domain\ValueObjects\ResourceProfile;

/**
 * Employees, contracts, FTE and working locations (Mod 068, Mod 072).
 */
interface ResourceDirectoryPort
{
    /**
     * The employees with an active contract on $date, optionally restricted to ids and to a department, ordered by name.
     *
     * @param  list<string>|null  $employeeIds
     * @return list<ResourceProfile>
     */
    public function resources(?array $employeeIds, ?string $departmentId, CarbonImmutable $date): array;

    /**
     * The full name of employees, also of those without an active contract (a past allocation outlives a contract).
     *
     * @param  list<string>  $employeeIds
     * @return array<string, string> full name by employee id
     */
    public function names(array $employeeIds): array;
}
