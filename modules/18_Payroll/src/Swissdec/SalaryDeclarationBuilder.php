<?php

declare(strict_types=1);

namespace Modules\Payroll\Swissdec;

use App\Models\Tenant;
use Illuminate\Support\Collection;
use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\PayrollDates;
use Modules\Payroll\Calculation\PayslipLineData;
use Modules\Payroll\Calculation\Swiss\SalaryCertificateBoxes;
use Modules\Payroll\Calculation\Swiss\SwissWageTypeCodes;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\PayslipLine;
use Modules\Payroll\Models\SwissPayrollSettings;
use Modules\Payroll\Services\Withholding\WithholdingTaxAmounts;

/**
 * Builds a complete Swissdec ELM 5.0 / 6.0 salary declaration covering all 10 Swissdec domains:
 * AHV-AVS, UVG-LAA, UVGZ-LAAC, KTG-AMC, BVG-LPP, FAK-CAF, TaxAtSource, Tax (Lohnausweis Form 11),
 * Statistic (BFS/LSE), and TaxCrossborder.
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
        $settings = SwissPayrollSettings::query()->first();

        $persons = [];
        $totalAhv = '0';
        $totalAlv = '0';
        $totalUvgBase = '0';
        $totalUvgContrib = '0';
        $totalUvgzBase = '0';
        $totalUvgzContrib = '0';
        $totalKtgRef = '0';
        $totalKtgContrib = '0';
        $totalBvgBasis = '0';
        $totalBvgEmp = '0';
        $totalBvgEmpr = '0';
        $totalFakBasis = '0';
        $totalFakAllowances = '0';
        $totalQstTaxable = '0';
        $totalQstTax = '0';
        $totalTaxGross = '0';
        $totalTaxNet = '0';
        $totalTaxQst = '0';
        $totalCrossborderTaxable = '0';
        $totalCrossborderTax = '0';
        $qstCount = 0;
        $crossborderCount = 0;

        foreach ($payslips as $employeeId => $slips) {
            $person = $this->person($employees->get($employeeId), $profiles->get($employeeId), $slips, $year, $settings);
            $persons[] = $person;

            $ahvSalary = $person['AHV-AVS-Salaries']['AHV-AVS-Salary'];
            $uvgSalary = $person['UVG-LAA-Salaries']['UVG-LAA-Salary'];
            $uvgzSalary = $person['UVGZ-LAAC-Salaries']['UVGZ-LAAC-Salary'];
            $ktgSalary = $person['KTG-AMC-Salaries']['KTG-AMC-Salary'];
            $bvgSalary = $person['BVG-LPP-Salaries']['BVG-LPP-Salary'];
            $fakSalary = $person['FAK-CAF-Salaries']['FAK-CAF-Salary'];
            $taxSalary = $person['TaxSalaries']['TaxSalary'];

            $totalAhv = Dec::add($totalAhv, $ahvSalary['AHV-AVS-Income']);
            $totalAlv = Dec::add($totalAlv, $ahvSalary['ALV-AC-Income']);
            $totalUvgBase = Dec::add($totalUvgBase, $uvgSalary['UVG-LAA-BaseSalary']);
            $totalUvgContrib = Dec::add($totalUvgContrib, $uvgSalary['UVG-LAA-ContributorySalary']);
            $totalUvgzBase = Dec::add($totalUvgzBase, $uvgzSalary['UVGZ-LAAC-BaseSalary']);
            $totalUvgzContrib = Dec::add($totalUvgzContrib, $uvgzSalary['UVGZ-LAAC-ContributorySalary']);
            $totalKtgRef = Dec::add($totalKtgRef, $ktgSalary['KTG-AMC-ReferenceAHV-AVS-Salary']);
            $totalKtgContrib = Dec::add($totalKtgContrib, $ktgSalary['KTG-AMC-ContributorySalary']);
            $totalBvgBasis = Dec::add($totalBvgBasis, $bvgSalary['BVG-LPP-AnnualBasis']);
            $totalBvgEmp = Dec::add($totalBvgEmp, $bvgSalary['BVG-LPP-EmployeeContribution']);
            $totalBvgEmpr = Dec::add($totalBvgEmpr, $bvgSalary['BVG-LPP-EmployerContribution']);
            $totalFakBasis = Dec::add($totalFakBasis, $fakSalary['FAK-CAF-ContributorySalary']);
            $totalFakAllowances = Dec::add($totalFakAllowances, $fakSalary['FAK-CAF-FamilyIncomeSupplement']);
            $totalTaxGross = Dec::add($totalTaxGross, $taxSalary['GrossIncome']);
            $totalTaxNet = Dec::add($totalTaxNet, $taxSalary['NetIncome']);
            $totalTaxQst = Dec::add($totalTaxQst, $taxSalary['DeductionAtSource']);

            if (isset($person['TaxAtSourceSalaries']['TaxAtSourceSalary'])) {
                $qstCount += count($person['TaxAtSourceSalaries']['TaxAtSourceSalary']);

                foreach ($person['TaxAtSourceSalaries']['TaxAtSourceSalary'] as $qstRow) {
                    $totalQstTaxable = Dec::add($totalQstTaxable, (string) $qstRow['TaxableEarning']);
                    $totalQstTax = Dec::add($totalQstTax, (string) $qstRow['TaxAtSource']);
                }
            }

            if (isset($person['TaxCrossborderSalaries']['TaxCrossborderSalary'])) {
                $crossborderCount++;
                $cb = $person['TaxCrossborderSalaries']['TaxCrossborderSalary'];
                $totalCrossborderTaxable = Dec::add($totalCrossborderTaxable, (string) $cb['TaxableEarning']);
                $totalCrossborderTax = Dec::add($totalCrossborderTax, (string) $cb['TaxAtSource']);
            }
        }

        return [
            'certification' => SwissdecCertificationStatus::toArray(),
            'SalaryDeclaration' => [
                'Company' => [
                    'CompanyDescription' => [
                        'Name' => ['HR-RC-Name' => $tenant->legal_name ?? $tenant->name],
                        'UID-BFS' => ['UID' => $tenant->vat_number ?? 'CHE-000.000.000'],
                    ],
                    'Person' => $persons,
                    'Institutions' => [
                        'AHV-AVS' => ['AK-CC-CustomerNumber' => $settings?->ahv_member_number],
                        'UVG-LAA' => ['InsurerName' => ($settings !== null ? $settings->uvg_insurer : null) ?? 'SUVA'],
                        'UVGZ-LAAC' => ['InsurerName' => ($settings !== null ? $settings->uvg_insurer : null) ?? 'SUVA'],
                        'KTG-AMC' => ['InsurerName' => 'KTG-Collective'],
                        'BVG-LPP' => ['InsurerName' => ($settings !== null ? $settings->bvg_insurer : null) ?? 'LPP-Foundation'],
                        'FAK-CAF' => ['AK-CC-CustomerNumber' => $settings?->ahv_member_number],
                        'TaxAtSource' => ['Canton' => ($settings !== null ? $settings->default_work_canton : null) ?? 'TI'],
                        'Tax' => ['Canton' => ($settings !== null ? $settings->default_work_canton : null) ?? 'TI'],
                        'Statistic' => ['BFS-WorkplaceID' => '1'],
                        'TaxCrossborder' => ['Canton' => ($settings !== null ? $settings->default_work_canton : null) ?? 'TI'],
                    ],
                    'SalaryTotals' => [
                        'AHV-AVS-Totals' => [
                            'Total-AHV-AVS-Incomes' => Dec::round($totalAhv),
                            'Total-ALV-AC-Incomes' => Dec::round($totalAlv),
                            'Total-ALVZ-ACS-Incomes' => '0.00',
                            'Total-FAK-CAF-Incomes' => Dec::round($totalFakBasis),
                        ],
                        'UVG-LAA-Totals' => [
                            'Total-UVG-LAA-BaseSalary' => Dec::round($totalUvgBase),
                            'Total-UVG-LAA-ContributorySalary' => Dec::round($totalUvgContrib),
                        ],
                        'UVGZ-LAAC-Totals' => [
                            'Total-UVGZ-LAAC-BaseSalary' => Dec::round($totalUvgzBase),
                            'Total-UVGZ-LAAC-ContributorySalary' => Dec::round($totalUvgzContrib),
                        ],
                        'KTG-AMC-Totals' => [
                            'Total-KTG-AMC-ReferenceAHV-AVS-Salary' => Dec::round($totalKtgRef),
                            'Total-KTG-AMC-ContributorySalary' => Dec::round($totalKtgContrib),
                        ],
                        'BVG-LPP-Totals' => [
                            'Total-BVG-LPP-AnnualBasis' => Dec::round($totalBvgBasis),
                            'Total-BVG-LPP-EmployeeContributions' => Dec::round($totalBvgEmp),
                            'Total-BVG-LPP-EmployerContributions' => Dec::round($totalBvgEmpr),
                        ],
                        'FAK-CAF-Totals' => [
                            'Total-FAK-CAF-ContributorySalary' => Dec::round($totalFakBasis),
                            'Total-FAK-CAF-FamilyIncomeSupplement' => Dec::round($totalFakAllowances),
                        ],
                        'TaxAtSourceTotals' => [
                            'Total-TaxableEarning' => Dec::round($totalQstTaxable),
                            'Total-TaxAtSource' => Dec::round($totalQstTax),
                        ],
                        'TaxTotals' => [
                            'Total-GrossIncome' => Dec::round($totalTaxGross),
                            'Total-NetIncome' => Dec::round($totalTaxNet),
                            'Total-DeductionAtSource' => Dec::round($totalTaxQst),
                        ],
                        'TaxCrossborderTotals' => [
                            'Total-CrossborderTaxableEarning' => Dec::round($totalCrossborderTaxable),
                            'Total-CrossborderTaxAtSource' => Dec::round($totalCrossborderTax),
                        ],
                    ],
                    'SalaryCounters' => [
                        'NumberOf-AHV-AVS-Salary-Tags' => count($persons),
                        'NumberOf-UVG-LAA-Salary-Tags' => count($persons),
                        'NumberOf-UVGZ-LAAC-Salary-Tags' => count($persons),
                        'NumberOf-KTG-AMC-Salary-Tags' => count($persons),
                        'NumberOf-BVG-LPP-Salary-Tags' => count($persons),
                        'NumberOf-FAK-CAF-Salary-Tags' => count($persons),
                        'NumberOf-TaxAtSource-Salary-Tags' => $qstCount,
                        'NumberOf-Tax-Salary-Tags' => count($persons),
                        'NumberOf-Statistic-Salary-Tags' => count($persons),
                        'NumberOf-TaxCrossborder-Salary-Tags' => $crossborderCount,
                    ],
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
    private function person(?Employee $employee, ?PayrollEmployeeProfile $profile, Collection $slips, int $year, ?SwissPayrollSettings $settings): array
    {
        $number = $profile?->social_security_number ?? $employee?->social_security_number;
        $first = PayrollDates::monthStart($year, (int) $slips->min('month'))->format('Y-m-d');
        $last = PayrollDates::monthEnd($year, (int) $slips->max('month'))->format('Y-m-d');
        $sum = static fn (string $column): string => Dec::round(Dec::sum($slips->map(static fn (Payslip $payslip): string => (string) $payslip->{$column})));
        $amounts = WithholdingTaxAmounts::of($slips);

        $allLines = $slips->flatMap(static fn (Payslip $payslip): Collection => $payslip->lines);
        $lineDatas = $allLines->map(static fn (PayslipLine $line): PayslipLineData => new PayslipLineData(
            $line->wage_type_code,
            $line->description,
            $line->kind,
            (string) $line->quantity,
            (string) $line->rate,
            (string) $line->amount,
            $line->sort_order,
            $line->certificate_box,
        ))->values()->all();

        $boxes = SalaryCertificateBoxes::fill($lineDatas, $employee, $profile);

        $weeklyHours = (string) ($profile !== null && $profile->weekly_hours > 0 ? $profile->weekly_hours : '42.00');
        $nbuDue = Dec::isZero($weeklyHours) || Dec::cmp($weeklyHours, '8') >= 0;
        $workCanton = strtoupper((string) ($profile !== null && $profile->work_canton !== '' ? $profile->work_canton : ($settings !== null && $settings->default_work_canton !== '' ? $settings->default_work_canton : 'TI')));
        $activityRate = $employee === null ? '100.00' : Dec::round(Dec::mul((string) $employee->fte_ratio, '100'));

        $bvgAnnualBasis = $slips->isEmpty()
            ? '0.00'
            : Dec::round(Dec::div($sum('bvg_insured_salary'), (string) $slips->count()));
        $bvgEmp = Dec::round(Dec::neg(Dec::sum($allLines->where('wage_type_code', SwissWageTypeCodes::Bvg)->map(static fn (PayslipLine $l): string => (string) $l->amount))));
        $bvgEmpr = Dec::round(Dec::sum($allLines->where('wage_type_code', SwissWageTypeCodes::BvgEmployer)->map(static fn (PayslipLine $l): string => (string) $l->amount)));
        $familySupplement = Dec::round(Dec::sum($allLines->whereIn('wage_type_code', [SwissWageTypeCodes::ChildAllowance, SwissWageTypeCodes::EducationAllowance])->map(static fn (PayslipLine $l): string => (string) $l->amount)));
        $thirteenthTotal = Dec::round(Dec::sum($allLines->where('wage_type_code', SwissWageTypeCodes::ThirteenthSalary)->map(static fn (PayslipLine $l): string => (string) $l->amount)));
        $overtimeTotal = Dec::round(Dec::sum($allLines->where('wage_type_code', '1010')->map(static fn (PayslipLine $l): string => (string) $l->amount)));

        $person = [
            'Particulars' => [
                'Social-InsuranceIdentification' => $number === null ? ['Unknown' => true] : ['SV-AS-Number' => $number],
                'EmployeeNumber' => $employee?->employee_number,
                'Lastname' => $employee?->last_name,
                'Firstname' => $employee?->first_name,
                'Sex' => $profile?->gender ?? $employee?->gender,
                'DateOfBirth' => $employee?->birth_date?->format('Y-m-d'),
                'Nationality' => $employee?->nationality,
                'ResidenceCategory' => $profile !== null ? ($profile->swiss_permit_type ?? $profile->residence_permit) : null,
                'CivilStatus' => ['Status' => $profile?->civil_status],
                'Address' => [
                    'Street' => trim("{$profile?->street} {$profile?->house_number}"),
                    'ZIP-Code' => $profile?->postal_code,
                    'City' => $profile?->city,
                    'Country' => $profile !== null && $profile->residence_country !== '' ? $profile->residence_country : 'CH',
                ],
            ],
            'Work' => [
                'EntryDate' => $employee?->hire_date?->format('Y-m-d'),
                'WithdrawalDate' => $employee?->termination_date?->format('Y-m-d'),
                'WorkingTime' => ['Steady' => ['WeeklyHours' => $weeklyHours]],
                'ActivityRate' => $activityRate,
            ],
            'AHV-AVS-Salaries' => [
                'AHV-AVS-Salary' => [
                    'AccountingTime' => ['from' => $first, 'until' => $last],
                    'AHV-AVS-Income' => $sum('ahv_basis'),
                    'ALV-AC-Income' => $sum('alv_basis'),
                    'ALVZ-ACS-Income' => '0.00',
                ],
            ],
            'UVG-LAA-Salaries' => [
                'UVG-LAA-Salary' => [
                    'AccountingTime' => ['from' => $first, 'until' => $last],
                    'WorkplaceID' => '1',
                    'UVG-LAA-Code' => $nbuDue ? 'A1' : 'B1',
                    'UVG-LAA-BaseSalary' => $sum('uvg_basis'),
                    'UVG-LAA-ContributorySalary' => $sum('uvg_basis'),
                ],
            ],
            'UVGZ-LAAC-Salaries' => [
                'UVGZ-LAAC-Salary' => [
                    'AccountingTime' => ['from' => $first, 'until' => $last],
                    'UVGZ-LAAC-Code' => '00',
                    'UVGZ-LAAC-BaseSalary' => $sum('uvg_basis'),
                    'UVGZ-LAAC-ContributorySalary' => '0.00',
                ],
            ],
            'KTG-AMC-Salaries' => [
                'KTG-AMC-Salary' => [
                    'AccountingTime' => ['from' => $first, 'until' => $last],
                    'KTG-AMC-Code' => '11',
                    'KTG-AMC-ReferenceAHV-AVS-Salary' => $sum('ktg_basis'),
                    'KTG-AMC-ContributorySalary' => $sum('ktg_basis'),
                ],
            ],
            'BVG-LPP-Salaries' => [
                'BVG-LPP-Salary' => [
                    'AccountingTime' => ['from' => $first, 'until' => $last],
                    'BVG-LPP-Code' => '01',
                    'BVG-LPP-AnnualBasis' => $bvgAnnualBasis,
                    'BVG-LPP-EmployeeContribution' => $bvgEmp,
                    'BVG-LPP-EmployerContribution' => $bvgEmpr,
                ],
            ],
            'FAK-CAF-Salaries' => [
                'FAK-CAF-Salary' => [
                    'AccountingTime' => ['from' => $first, 'until' => $last],
                    'FAK-CAF-WorkplaceCanton' => $workCanton,
                    'FAK-CAF-ContributorySalary' => $sum('ahv_basis'),
                    'FAK-CAF-FamilyIncomeSupplement' => $familySupplement,
                ],
            ],
        ];

        if ($slips->contains(static fn (Payslip $payslip): bool => $payslip->withholding_tax_canton !== null)) {
            $person['TaxAtSourceSalaries'] = ['TaxAtSourceSalary' => $slips->map(static fn (Payslip $payslip): array => [
                'Month' => $payslip->month,
                'TaxAtSourceCategory' => ['CategoryPredefined' => $payslip->withholding_tax_code],
                'CantonCH' => $payslip->withholding_tax_canton,
                'TaxableEarning' => Dec::round((string) $payslip->withholding_tax_basis),
                'TaxAtSource' => WithholdingTaxAmounts::of(collect([$payslip]))['tax'],
            ])->values()->all()];
        }

        $person['TaxSalaries'] = [
            'TaxSalary' => [
                'Period' => ['from' => $first, 'until' => $last],
                'FreeTransport' => (bool) $boxes['checkbox_f_transport'],
                'CanteenLunchChecks' => (bool) $boxes['checkbox_g_canteen'],
                'Income' => Dec::round((string) $boxes['box1_salary']),
                'FringeBenefits' => Dec::round((string) $boxes['box2_benefits']),
                'SporadicBenefits' => Dec::round((string) $boxes['box3_irregular']),
                'CapitalPayment' => Dec::round((string) $boxes['box4_capital']),
                'OtherBenefits' => Dec::round((string) $boxes['box7_other']),
                'GrossIncome' => Dec::round((string) $boxes['box8_gross']),
                'AHV-ALV-NBUV-AVS-AC-AANP-Contribution' => Dec::round((string) $boxes['box9_social_contributions']),
                'BVG-LPP-Contribution' => [
                    'Regular' => Dec::round((string) $boxes['box10_1_ordinary_pension']),
                    'Purchase' => Dec::round((string) $boxes['box10_2_pension_buyin']),
                ],
                'NetIncome' => Dec::round((string) $boxes['box11_net']),
                'DeductionAtSource' => Dec::round((string) $boxes['box12_withholding_tax']),
                'Charges' => [
                    'Effective' => Dec::round((string) $boxes['box13_1_effective_expenses']),
                    'LumpSum' => Dec::round((string) $boxes['box13_2_lump_sum_expenses']),
                    'Total' => Dec::round((string) $boxes['box13_expenses']),
                ],
                'Remark' => $boxes['box15_remarks'],
            ],
        ];

        $person['StatisticSalaries'] = [
            'StatisticSalary' => [
                'Month' => (int) $slips->max('month'),
                'Contract' => ($profile !== null && (bool) $profile->is_fixed_term_contract) ? 'fixedTerm' : 'indefinite',
                'PayType' => $profile !== null && $profile->pay_type !== '' ? $profile->pay_type : 'monthly',
                'WeeklyHours' => $weeklyHours,
                'ActivityRate' => $activityRate,
                'GrossWage' => $amounts['gross'],
                'ThirteenthSalary' => $thirteenthTotal,
                'Overtime' => $overtimeTotal,
                'SporadicBenefits' => Dec::round((string) $boxes['box3_irregular']),
                'SocialContributions' => $amounts['social_contributions'],
                'FamilyAllowances' => $familySupplement,
            ],
        ];

        $residenceCountry = strtoupper((string) ($profile !== null && $profile->residence_country !== '' ? $profile->residence_country : 'CH'));
        $permit = strtoupper((string) ($profile !== null ? ($profile->swiss_permit_type ?? $profile->residence_permit ?? '') : ''));

        if ($residenceCountry !== 'CH' || $permit === 'G') {
            $person['TaxCrossborderSalaries'] = [
                'TaxCrossborderSalary' => [
                    'AccountingTime' => ['from' => $first, 'until' => $last],
                    'ResidenceCountry' => $residenceCountry !== 'CH' ? $residenceCountry : 'IT',
                    'WorkplaceCanton' => $workCanton,
                    'TaxID' => $profile?->italian_tax_code,
                    'BirthPlace' => $profile?->birth_place,
                    'FrontierRegime' => $profile !== null && $profile->frontier_regime !== null ? $profile->frontier_regime : 'new',
                    'TaxableEarning' => $amounts['basis'],
                    'TaxAtSource' => $amounts['tax'],
                    'SocialContributions' => $amounts['social_contributions'],
                ],
            ];
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
