<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Contracts;

use Carbon\CarbonImmutable;
use Modules\ShiftPlanning\Domain\ValueObjects\EmployeeContractProfile;

/**
 * Employment contracts and skills (Mod 068).
 */
interface EmployeeContractPort
{
    /**
     * The contract profile in force on $date, keyed by employee id. An employee without an active contract is absent.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, EmployeeContractProfile>
     */
    public function profilesFor(array $employeeIds, CarbonImmutable $date): array;

    /**
     * Employees with an active contract on $date that hold the skill (every active employee when $skillId is null).
     *
     * @return list<string> employee ids
     */
    public function employeesWithSkill(?string $skillId, CarbonImmutable $date): array;
}
