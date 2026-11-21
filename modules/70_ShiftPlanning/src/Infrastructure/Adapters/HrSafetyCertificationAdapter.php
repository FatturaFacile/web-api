<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Infrastructure\Adapters;

use Carbon\CarbonImmutable;
use Modules\HumanResources\Models\Certification;
use Modules\ShiftPlanning\Domain\Contracts\SafetyCertificationPort;
use Modules\ShiftPlanning\Domain\ValueObjects\CertificationIssue;

/**
 * Reads the certifications of the HR training module (Mod 099): a mandatory certificate that is revoked, or that expired
 * before the shift date, makes the employee unfit for the shift. A certificate without an expiry date does not expire.
 */
final class HrSafetyCertificationAdapter implements SafetyCertificationPort
{
    public function invalidMandatoryCertifications(string $employeeId, CarbonImmutable $date): array
    {
        return Certification::query()
            ->where('employee_id', $employeeId)
            ->where('is_mandatory', true)
            ->get()
            ->filter(static fn (Certification $certification): bool => $certification->status === 'revoked'
                || ($certification->expiry_date !== null && $certification->expiry_date->format('Y-m-d') < $date->format('Y-m-d')))
            ->map(static fn (Certification $certification): CertificationIssue => new CertificationIssue(
                (string) $certification->code,
                (string) $certification->name,
                $certification->expiry_date === null ? null : CarbonImmutable::parse($certification->expiry_date->format('Y-m-d')),
            ))
            ->values()
            ->all();
    }
}
