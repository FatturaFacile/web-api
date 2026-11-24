<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Contracts;

use Carbon\CarbonImmutable;

/**
 * The skills a technician holds according to the company skill matrix (Mod 079 resource planning), so that the dispatcher
 * matches work orders on the same competences the planners maintain, not only on the skills typed on the technician card.
 */
interface TechnicianSkillMatrixInterface
{
    /**
     * Lower-cased names of the skills of the employee that are valid on the day (proficiency recorded, not expired, and the
     * safety certificate of Mod 099 valid when the skill needs one). Empty for a technician without an employee record.
     *
     * @return list<string>
     */
    public function skillsOf(string $tenantId, ?string $employeeId, CarbonImmutable $on): array;
}
