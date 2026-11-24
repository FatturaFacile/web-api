<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Domain\Contracts;

use Carbon\CarbonImmutable;
use Modules\ResourcePlanning\Domain\ValueObjects\CertificateValidity;

/**
 * Safety and compliance certificates (Mod 099).
 */
interface CertificatePort
{
    /**
     * Whether each employee holds a certificate of the type that is valid, not revoked, on every day from $from to $to.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, CertificateValidity> keyed by employee id; every requested employee is present
     */
    public function validity(array $employeeIds, string $certificateCode, CarbonImmutable $from, CarbonImmutable $to): array;
}
