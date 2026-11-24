<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Infrastructure\Adapters;

use Carbon\CarbonImmutable;
use Modules\Hse\Domain\Enums\CertificateStatus;
use Modules\Hse\Infrastructure\Models\EmployeeSafetyCertificate;
use Modules\HumanResources\Models\Certification;
use Modules\ResourcePlanning\Domain\Contracts\CertificatePort;
use Modules\ResourcePlanning\Domain\ValueObjects\CertificateValidity;

/**
 * Reads the safety certificates of the HSE module (Mod 099), with the certifications of the HR training register as a
 * fallback for tenants that keep them there. A revoked certificate never counts; one without an expiry date does not expire;
 * one that expires before the end of the period leaves the employee uncovered.
 */
final class SafetyCertificateAdapter implements CertificatePort
{
    public function validity(array $employeeIds, string $certificateCode, CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var array<string, list<CarbonImmutable|null>> $expiries expiry dates (null = none) of the usable certificates */
        $expiries = [];

        $hse = EmployeeSafetyCertificate::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('certificate_type', $certificateCode)
            ->whereNull('revoked_at')
            ->get();

        foreach ($hse as $certificate) {
            if ($certificate->status === CertificateStatus::Revoked) {
                continue;
            }

            $expiries[(string) $certificate->employee_id][] = $certificate->expires_at === null ? null : CarbonImmutable::parse($certificate->expires_at->format('Y-m-d'));
        }

        $hr = Certification::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('code', $certificateCode)
            ->where('status', '!=', 'revoked')
            ->get();

        foreach ($hr as $certification) {
            $expiries[(string) $certification->employee_id][] = $certification->expiry_date === null ? null : CarbonImmutable::parse($certification->expiry_date->format('Y-m-d'));
        }

        $result = [];

        foreach ($employeeIds as $employeeId) {
            $result[$employeeId] = $this->evaluate($expiries[$employeeId] ?? [], $to);
        }

        return $result;
    }

    /**
     * @param  list<CarbonImmutable|null>  $expiries
     */
    private function evaluate(array $expiries, CarbonImmutable $to): CertificateValidity
    {
        if ($expiries === []) {
            return new CertificateValidity(CertificateValidity::Missing);
        }

        if (in_array(null, $expiries, true)) {
            return new CertificateValidity(CertificateValidity::Valid);
        }

        $latest = null;

        foreach ($expiries as $expiry) {
            if ($latest === null || $expiry > $latest) {
                $latest = $expiry;
            }
        }

        return $latest->format('Y-m-d') >= $to->format('Y-m-d')
            ? new CertificateValidity(CertificateValidity::Valid, $latest)
            : new CertificateValidity(CertificateValidity::Expired, $latest);
    }
}
