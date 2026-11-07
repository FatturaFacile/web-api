<?php

declare(strict_types=1);

namespace Modules\Payroll\Swissdec;

use App\Models\Tenant;
use Illuminate\Support\Collection;
use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\PayrollDates;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\SwissPayrollSettings;
use Modules\Payroll\Services\Withholding\WithholdingTaxAmounts;

/**
 * Builds a salary declaration as a data structure named after the elements of the public ELM 5.0 field overview: Company
 * with CompanyDescription, Person, SalaryTotals and SalaryCounters, then GeneralSalaryDeclarationDescription. It is a data
 * layer for a future certified transmission, not a Swissdec message: it is not validated against an XSD, it covers only the
 * AHV and tax at source domains, and readiness() lists what stands in the way.
 */
final class SalaryDeclarationBuilder
{
    /**
     * @return array{certification: array<string, mixed>, SalaryDeclaration: array<string, mixed>}
     */
    public function build(Tenant $tenant, int $year): array
    {
        $payslips = $this->closedPayslips($year);
        $employees = Employee::query()->withTrashed()->whereIn('id', $payslips->keys())->get()->keyBy('id');
        $profiles = PayrollEmployeeProfile::query()->whereIn('employee_id', $payslips->keys())->get()->keyBy('employee_id');
        $persons = [];
        $totalAhv = '0';
        $totalAlv = '0';

        foreach ($payslips as $employeeId => $slips) {
            $person = $this->person($employees->get($employeeId), $profiles->get($employeeId), $slips, $year);
            $persons[] = $person;
            $totalAhv = Dec::add($totalAhv, $person['AHV-AVS-Salaries']['AHV-AVS-Salary']['AHV-AVS-Income']);
            $totalAlv = Dec::add($totalAlv, $person['AHV-AVS-Salaries']['AHV-AVS-Salary']['ALV-AC-Income']);
        }

        return [
            'certification' => SwissdecCertificationStatus::toArray(),
            'SalaryDeclaration' => [
                'Company' => [
                    'CompanyDescription' => [
                        'Name' => ['HR-RC-Name' => $tenant->legal_name],
                        'UID-BFS' => ['UID' => $tenant->vat_number],
                    ],
                    'Person' => $persons,
                    'Institutions' => ['AHV-AVS' => ['AK-CC-CustomerNumber' => SwissPayrollSettings::query()->value('ahv_member_number')]],
                    'SalaryTotals' => ['AHV-AVS-Totals' => ['Total-AHV-AVS-Incomes' => Dec::round($totalAhv), 'Total-ALV-AC-Incomes' => Dec::round($totalAlv)]],
                    'SalaryCounters' => ['NumberOf-AHV-AVS-Salary-Tags' => count($persons)],
                ],
                'GeneralSalaryDeclarationDescription' => [
                    'CreationDate' => now()->toIso8601String(),
                    'AccountingPeriod' => $year,
                ],
            ],
        ];
    }

    /**
     * What is missing or wrong in the data of a year, per person and for the company.
     *
     * @return array{company: list<string>, persons: list<array{employee_id: string, employee_name: string, issues: list<string>}>, is_complete: bool}
     */
    public function readiness(Tenant $tenant, int $year): array
    {
        $payslips = $this->closedPayslips($year);
        $employees = Employee::query()->withTrashed()->whereIn('id', $payslips->keys())->get()->keyBy('id');
        $profiles = PayrollEmployeeProfile::query()->whereIn('employee_id', $payslips->keys())->get()->keyBy('employee_id');
        $company = array_values(array_filter([
            $tenant->legal_name === null ? 'Ragione sociale mancante.' : null,
            $tenant->vat_number === null ? 'Numero IDI (UID) mancante.' : null,
            SwissPayrollSettings::query()->value('ahv_member_number') === null ? 'Numero di affiliato AVS mancante.' : null,
        ]));
        $persons = [];

        foreach ($payslips->keys() as $employeeId) {
            $employee = $employees->get($employeeId);
            $issues = $this->personIssues($employee, $profiles->get($employeeId));

            if ($issues !== []) {
                $persons[] = ['employee_id' => (string) $employeeId, 'employee_name' => (string) $employee?->full_name, 'issues' => $issues];
            }
        }

        return ['company' => $company, 'persons' => $persons, 'is_complete' => $company === [] && $persons === []];
    }

    /**
     * The check digit of an AHV number (EAN-13), which the salary standard requires to be valid.
     */
    public static function isValidSocialSecurityNumber(?string $number): bool
    {
        $digits = preg_replace('/\D/', '', (string) $number) ?? '';

        if (strlen($digits) !== 13 || ! str_starts_with($digits, '756')) {
            return false;
        }

        $sum = 0;

        for ($index = 0; $index < 12; $index++) {
            $sum += (int) $digits[$index] * ($index % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10 === (int) $digits[12];
    }

    /**
     * @return Collection<string, Collection<int, Payslip>>
     */
    private function closedPayslips(int $year): Collection
    {
        $runs = PayrollRun::query()->where('year', $year)->where('status', PayrollRun::StatusFinal)->pluck('id');
        $payslips = Payslip::query()->with('lines')->whereIn('payroll_run_id', $runs)->orderBy('month')->get();

        if ($payslips->isEmpty()) {
            throw new PayrollException("Nessuna elaborazione chiusa nell'anno.");
        }

        return $payslips->groupBy('employee_id');
    }

    /**
     * @param  Collection<int, Payslip>  $slips
     * @return array<string, mixed>
     */
    private function person(?Employee $employee, ?PayrollEmployeeProfile $profile, Collection $slips, int $year): array
    {
        $number = $profile?->social_security_number ?? $employee?->social_security_number;
        $first = PayrollDates::monthStart($year, (int) $slips->min('month'))->format('Y-m-d');
        $last = PayrollDates::monthEnd($year, (int) $slips->max('month'))->format('Y-m-d');
        $sum = static fn (string $column): string => Dec::round(Dec::sum($slips->map(static fn (Payslip $payslip): string => (string) $payslip->{$column})));
        $amounts = WithholdingTaxAmounts::of($slips);

        $person = [
            'Particulars' => [
                'Social-InsuranceIdentification' => $number === null ? ['Unknown' => true] : ['SV-AS-Number' => $number],
                'EmployeeNumber' => $employee?->employee_number,
                'Lastname' => $employee?->last_name,
                'Firstname' => $employee?->first_name,
                'Sex' => $profile?->gender ?? $employee?->gender,
                'DateOfBirth' => $employee?->birth_date?->format('Y-m-d'),
                'Nationality' => $employee?->nationality,
                'CivilStatus' => ['Status' => $profile?->civil_status],
                'Address' => [
                    'Street' => trim("{$profile?->street} {$profile?->house_number}"),
                    'ZIP-Code' => $profile?->postal_code,
                    'City' => $profile?->city,
                    'Country' => $profile?->residence_country,
                ],
            ],
            'Work' => [
                'EntryDate' => $employee?->hire_date?->format('Y-m-d'),
                'WithdrawalDate' => $employee?->termination_date?->format('Y-m-d'),
                'WorkingTime' => ['Steady' => ['WeeklyHours' => (string) $profile?->weekly_hours]],
                'ActivityRate' => $employee === null ? null : Dec::round(Dec::mul((string) $employee->fte_ratio, '100')),
            ],
            'AHV-AVS-Salaries' => [
                'AHV-AVS-Salary' => [
                    'AccountingTime' => ['from' => $first, 'until' => $last],
                    'AHV-AVS-Income' => $sum('ahv_basis'),
                    'ALV-AC-Income' => $sum('alv_basis'),
                ],
            ],
        ];

        if ($slips->contains(static fn (Payslip $payslip): bool => $payslip->withholding_tax_canton !== null)) {
            $person['TaxAtSourceSalaries'] = ['TaxAtSourceSalary' => $slips->map(static fn (Payslip $payslip): array => [
                'Month' => $payslip->month,
                'TaxAtSourceCategory' => ['CategoryPredefined' => $payslip->withholding_tax_code],
                'CantonCH' => $payslip->withholding_tax_canton,
                'TaxableEarning' => (string) $payslip->withholding_tax_basis,
                'TaxAtSource' => WithholdingTaxAmounts::of(collect([$payslip]))['tax'],
            ])->values()->all()];
        }

        $person['GrossTotal'] = $amounts['gross'];

        return $person;
    }

    /**
     * @return list<string>
     */
    private function personIssues(?Employee $employee, ?PayrollEmployeeProfile $profile): array
    {
        if ($employee === null) {
            return ['Dipendente non trovato.'];
        }

        $number = $profile?->social_security_number ?? $employee->social_security_number;

        return array_values(array_filter([
            $number === null ? 'Numero AVS mancante.' : (self::isValidSocialSecurityNumber($number) ? null : 'Numero AVS non valido.'),
            $employee->birth_date === null ? 'Data di nascita mancante.' : null,
            ($profile?->gender ?? $employee->gender) === null ? 'Sesso mancante.' : null,
            $employee->nationality === null ? 'Nazionalità mancante.' : null,
            $profile?->civil_status === null ? 'Stato civile mancante.' : null,
            $profile?->street === null ? 'Indirizzo mancante.' : null,
        ]));
    }
}
