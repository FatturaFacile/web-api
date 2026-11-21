<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Contracts;

use Carbon\CarbonImmutable;
use Modules\ShiftPlanning\Domain\ValueObjects\CertificationIssue;

/**
 * Safety and HSE training validity (Mod 099).
 */
interface SafetyCertificationPort
{
    /**
     * The mandatory certificates of the employee that are not valid on $date.
     *
     * @return list<CertificationIssue>
     */
    public function invalidMandatoryCertifications(string $employeeId, CarbonImmutable $date): array;
}
