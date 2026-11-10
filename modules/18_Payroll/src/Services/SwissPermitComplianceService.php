<?php

declare(strict_types=1);

namespace Modules\Payroll\Services;

use Carbon\CarbonImmutable;
use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Enums\SwissPermitType;
use Modules\Payroll\Models\PayrollEmployeeProfile;

/**
 * Audits Swiss work permits (B, C, G, L, Ci, F, N, S, CH, Meldeverfahren) for expiration and
 * withholding tax (Quellensteuer) consistency under FTA Circular No. 45.
 */
final class SwissPermitComplianceService
{
    public const int DefaultWarningWindowDays = 60;

    /**
     * @return array{
     *     as_of: string,
     *     warning_window_days: int,
     *     summary: array{
     *         total_profiles_checked: int,
     *         expired_count: int,
     *         expiring_soon_count: int,
     *         qst_mismatch_count: int,
     *         is_compliant: bool
     *     },
     *     alerts: list<array{
     *         employee_id: string,
     *         employee_name: string,
     *         permit_type: string|null,
     *         permit_number: string|null,
     *         valid_until: string|null,
     *         days_remaining: int|null,
     *         severity: string,
     *         type: string,
     *         message: string
     *     }>
     * }
     */
    public function audit(?CarbonImmutable $asOf = null, int $warningWindowDays = self::DefaultWarningWindowDays): array
    {
        $referenceDate = ($asOf ?? CarbonImmutable::today())->startOfDay();
        $profiles = PayrollEmployeeProfile::query()->with('employee')->get();
        $alerts = [];
        $expiredCount = 0;
        $expiringCount = 0;
        $qstMismatchCount = 0;

        foreach ($profiles as $profile) {
            /** @var Employee|null $employee */
            $employee = $profile->employee;

            if ($employee !== null && $employee->termination_date !== null && CarbonImmutable::parse($employee->termination_date)->lt($referenceDate)) {
                continue;
            }

            $rawPermit = $profile->swiss_permit_type ?? $profile->residence_permit;
            $permit = SwissPermitType::tryFromInsensitive($rawPermit);

            if ($permit === null && $rawPermit === null) {
                continue;
            }

            $employeeId = (string) $profile->employee_id;
            $employeeName = (string) ($employee !== null ? ($employee->full_name ?? $employeeId) : $employeeId);
            $permitCode = $permit !== null ? $permit->value : (string) $rawPermit;
            $validUntil = $profile->swiss_permit_valid_until !== null
                ? CarbonImmutable::parse($profile->swiss_permit_valid_until)->startOfDay()
                : null;
            $daysRemaining = $validUntil !== null
                ? (int) $referenceDate->diffInDays($validUntil, false)
                : null;

            if ($validUntil !== null) {
                if ($daysRemaining < 0) {
                    $expiredCount++;
                    $alerts[] = [
                        'employee_id' => $employeeId,
                        'employee_name' => $employeeName,
                        'permit_type' => $permitCode,
                        'permit_number' => $profile->swiss_permit_number,
                        'valid_until' => $validUntil->format('Y-m-d'),
                        'days_remaining' => $daysRemaining,
                        'severity' => 'error',
                        'type' => 'permit_expired',
                        'message' => "Il permesso {$permitCode} è scaduto il {$validUntil->format('d.m.Y')}.",
                    ];
                } elseif ($daysRemaining <= $warningWindowDays) {
                    $expiringCount++;
                    $alerts[] = [
                        'employee_id' => $employeeId,
                        'employee_name' => $employeeName,
                        'permit_type' => $permitCode,
                        'permit_number' => $profile->swiss_permit_number,
                        'valid_until' => $validUntil->format('Y-m-d'),
                        'days_remaining' => $daysRemaining,
                        'severity' => 'warning',
                        'type' => 'permit_expiring',
                        'message' => "Il permesso {$permitCode} scade tra {$daysRemaining} giorni ({$validUntil->format('d.m.Y')}).",
                    ];
                }
            } elseif ($permit !== null && $permit->requiresValidityDate()) {
                $alerts[] = [
                    'employee_id' => $employeeId,
                    'employee_name' => $employeeName,
                    'permit_type' => $permitCode,
                    'permit_number' => $profile->swiss_permit_number,
                    'valid_until' => null,
                    'days_remaining' => null,
                    'severity' => 'warning',
                    'type' => 'permit_validity_missing',
                    'message' => "Data di scadenza mancante per il permesso {$permitCode}.",
                ];
            }

            if ($permit !== null) {
                $residenceCountry = strtoupper((string) ($profile->residence_country ?? 'CH'));
                $requiresQst = $permit->requiresWithholdingTax($residenceCountry);

                if ($requiresQst && (! $profile->is_withholding_tax_subject || trim((string) $profile->withholding_tax_code) === '')) {
                    $qstMismatchCount++;
                    $alerts[] = [
                        'employee_id' => $employeeId,
                        'employee_name' => $employeeName,
                        'permit_type' => $permitCode,
                        'permit_number' => $profile->swiss_permit_number,
                        'valid_until' => $validUntil?->format('Y-m-d'),
                        'days_remaining' => $daysRemaining,
                        'severity' => 'error',
                        'type' => 'qst_mismatch',
                        'message' => "Il permesso {$permitCode} richiede l'assoggettamento all'imposta alla fonte e un codice tariffario QST valido.",
                    ];
                } elseif (! $requiresQst && $profile->is_withholding_tax_subject) {
                    $qstMismatchCount++;
                    $alerts[] = [
                        'employee_id' => $employeeId,
                        'employee_name' => $employeeName,
                        'permit_type' => $permitCode,
                        'permit_number' => $profile->swiss_permit_number,
                        'valid_until' => $validUntil?->format('Y-m-d'),
                        'days_remaining' => $daysRemaining,
                        'severity' => 'error',
                        'type' => 'qst_mismatch',
                        'message' => "Il titolare di {$permitCode} residente in Svizzera è soggetto a tassazione ordinaria (is_withholding_tax_subject deve essere false).",
                    ];
                }

                if ($permit === SwissPermitType::G && $residenceCountry === 'CH') {
                    $alerts[] = [
                        'employee_id' => $employeeId,
                        'employee_name' => $employeeName,
                        'permit_type' => $permitCode,
                        'permit_number' => $profile->swiss_permit_number,
                        'valid_until' => $validUntil?->format('Y-m-d'),
                        'days_remaining' => $daysRemaining,
                        'severity' => 'warning',
                        'type' => 'permit_residence_mismatch',
                        'message' => 'Il permesso G (frontaliere) presuppone la residenza fuori dalla Svizzera.',
                    ];
                }
            }
        }

        return [
            'as_of' => $referenceDate->format('Y-m-d'),
            'warning_window_days' => $warningWindowDays,
            'summary' => [
                'total_profiles_checked' => $profiles->count(),
                'expired_count' => $expiredCount,
                'expiring_soon_count' => $expiringCount,
                'qst_mismatch_count' => $qstMismatchCount,
                'is_compliant' => $expiredCount === 0 && $qstMismatchCount === 0,
            ],
            'alerts' => $alerts,
        ];
    }
}
