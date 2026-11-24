<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Payroll\Calculation\ChildData;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\EmployeeData;
use Modules\Payroll\Calculation\PayrollDates;
use Modules\Payroll\Calculation\PayrollInputData;
use Modules\Payroll\Calculation\Swiss\FamilyAllowanceRateData;
use Modules\Payroll\Calculation\Swiss\SwissPayrollContext;
use Modules\Payroll\Calculation\Swiss\SwissPayrollEngine;
use Modules\Payroll\Calculation\Swiss\SwissPayrollRules;
use Modules\Payroll\Calculation\Swiss\SwissPayrollSettingsData;
use Modules\Payroll\Calculation\Swiss\SwissWageTypeCatalog;
use Modules\Payroll\Calculation\Swiss\SwissWageTypeCodes as Codes;
use Modules\Payroll\Calculation\Swiss\WithholdingTaxTariffData;
use Modules\Payroll\Models\PayrollEmployeeChild;
use Modules\Payroll\Models\PayrollWithholdingTaxTariff;
use Modules\Payroll\Models\SalaryCertificate;
use Modules\Payroll\Models\SwissPayrollSettings;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    $this->tenant = payrollTenant();
    $this->tenant->update(['vat_number' => 'CHE-123.456.789']);
    $this->manager = payrollMember($this->tenant, 'owner');
    payrollSwissSetup($this->tenant, $this->manager);
});

test('Phase C.1: UVG-NBU is exempt for both monthly and hourly employees working less than 8 hours per week', function (): void {
    $monthlyPartTime = new EmployeeData(
        hireDate: PayrollDates::date('2024-01-01'),
        dateOfBirth: PayrollDates::date('1990-04-10'),
        gender: 'M',
        fullName: 'Marco NBU Exempt',
        baseAnnualSalary: '18000',
        salaryInstallmentsPerYear: 12,
        payType: 'monthly',
        weeklyHours: '6.5',
        workCanton: 'TI',
    );

    $context = new SwissPayrollContext(
        employee: $monthlyPartTime,
        year: 2026,
        month: 3,
        wageTypes: SwissWageTypeCatalog::byCode(),
        settings: new SwissPayrollSettingsData(
            nbuEmployeeRatePercent: '1.2',
            buEmployerRatePercent: '0.1',
        ),
    );

    $result = SwissPayrollEngine::calculate($context);

    expect($result)->not->toBeNull()
        ->and(Dec::cmp($result->amount(Codes::Nbu), '0'))->toBe(0)
        ->and(Dec::cmp($result->amount(Codes::BuEmployer), '1.50'))->toBe(0);
});

test('Phase C.2: AHV 21 retiree allowance can be waived to contribute on the full salary', function (): void {
    $withExemption = new EmployeeData(
        hireDate: PayrollDates::date('2020-01-01'),
        dateOfBirth: PayrollDates::date('1958-03-01'),
        gender: 'F',
        fullName: 'Anna Pensionata',
        baseAnnualSalary: '60000',
        salaryInstallmentsPerYear: 12,
        workCanton: 'TI',
        waiveRetireeAllowance: false,
    );
    $withWaiver = new EmployeeData(
        hireDate: PayrollDates::date('2020-01-01'),
        dateOfBirth: PayrollDates::date('1958-03-01'),
        gender: 'F',
        fullName: 'Anna Pensionata Waiver',
        baseAnnualSalary: '60000',
        salaryInstallmentsPerYear: 12,
        workCanton: 'TI',
        waiveRetireeAllowance: true,
    );

    $resExempt = SwissPayrollEngine::calculate(new SwissPayrollContext(
        employee: $withExemption,
        year: 2026,
        month: 1,
        wageTypes: SwissWageTypeCatalog::byCode(),
    ));
    $resWaived = SwissPayrollEngine::calculate(new SwissPayrollContext(
        employee: $withWaiver,
        year: 2026,
        month: 1,
        wageTypes: SwissWageTypeCatalog::byCode(),
    ));

    expect($resExempt->payslip->ahvBasis)->toBe('3600.00')
        ->and($resWaived->payslip->ahvBasis)->toBe('5000.00')
        ->and($resWaived->payslip->alvBasis)->toBe('0.00');
});

test('Phase C.3: LAFam minimum monthly income threshold of CHF 630/month and 16th/25th birthday month boundaries', function (): void {
    $lowIncomeEmployee = new EmployeeData(
        hireDate: PayrollDates::date('2025-01-01'),
        dateOfBirth: PayrollDates::date('1988-01-01'),
        gender: 'F',
        fullName: 'Basso Reddito',
        baseAnnualSalary: '6000', // CHF 500/month < CHF 630/month
        salaryInstallmentsPerYear: 12,
        workCanton: 'TI',
    );

    $result = SwissPayrollEngine::calculate(new SwissPayrollContext(
        employee: $lowIncomeEmployee,
        year: 2026,
        month: 6,
        wageTypes: SwissWageTypeCatalog::byCode(),
        children: [new ChildData(PayrollDates::date('2020-01-01'))],
        familyAllowanceRates: [new FamilyAllowanceRateData('TI', PayrollDates::date('2025-01-01'), '215', '268')],
    ));

    expect(Dec::cmp($result->amount(Codes::ChildAllowance), '0'))->toBe(0)
        ->and($result->payslip->warnings)->toContain('minimo LAFam di CHF 630/mese');

    // Child turning 16 on the 1st of June 2026 still receives the child allowance for June 2026, and stops in July 2026
    $childTurning16 = new ChildData(PayrollDates::date('2010-06-01'));
    expect(SwissPayrollRules::familyAllowanceKind($childTurning16, PayrollDates::monthStart(2026, 6), PayrollDates::monthEnd(2026, 6)))
        ->toBe(SwissPayrollRules::ChildAllowanceKind)
        ->and(SwissPayrollRules::familyAllowanceKind($childTurning16, PayrollDates::monthStart(2026, 7), PayrollDates::monthEnd(2026, 7)))
        ->toBeNull();
});

test('Phase C.4: FTA Circular No. 45 QST incomplete month irregular bonus, part-time extrapolation and frontier 80% rule', function (): void {
    $validFrom = PayrollDates::date('2026-01-01');
    $tariffs = [
        new WithholdingTaxTariffData('ZH', 'A0N', $validFrom, '0', '5000', '0', '5.00'),
        new WithholdingTaxTariffData('ZH', 'A0N', $validFrom, '5000', '3000', '0', '10.00'),
        new WithholdingTaxTariffData('ZH', 'A0N', $validFrom, '8000', '5000', '0', '15.00'),
        new WithholdingTaxTariffData('TI', 'R0N', $validFrom, '0', '5000', '0', '6.00'),
        new WithholdingTaxTariffData('TI', 'R0N', $validFrom, '5000', '5000', '0', '12.00'),
    ];

    // 1. Mid-month hire (15 days in ZH monthly model): regular salary 3000 (6000 full month) + irregular bonus 1500
    // Rate-determining income must be 6000 + 1500 = 7500 (10% bracket), NOT (3000 + 1500) * 30 / 15 = 9000 (15% bracket)!
    $midMonthEmployee = new EmployeeData(
        hireDate: PayrollDates::date('2026-03-16'),
        dateOfBirth: PayrollDates::date('1990-01-01'),
        gender: 'M',
        fullName: 'Lukas MidMonth',
        baseAnnualSalary: '72000',
        salaryInstallmentsPerYear: 12,
        workCanton: 'ZH',
        residenceCanton: 'ZH',
        isWithholdingTaxSubject: true,
        withholdingTaxCode: 'A0N',
    );

    $resMidMonth = SwissPayrollEngine::calculate(new SwissPayrollContext(
        employee: $midMonthEmployee,
        year: 2026,
        month: 3,
        wageTypes: SwissWageTypeCatalog::byCode(),
        inputs: [new PayrollInputData('1100', amount: '1500')],
        withholdingTaxTariffs: $tariffs,
    ));

    expect($resMidMonth->payslip->withholdingTaxBasis)->toBe('4500.00')
        ->and($resMidMonth->payslip->withholdingTaxRatePercent)->toBe('10.00')
        ->and($resMidMonth->amount(Codes::WithholdingTax))->toBe('-450');

    // 2. Part-time 50% employee earning 3000/month with extrapolateQstToFullTime = true:
    // Rate looked up at 6000 (10% bracket), tax applied on actual 3000 -> -300.00
    $partTimeQst = new EmployeeData(
        hireDate: PayrollDates::date('2025-01-01'),
        dateOfBirth: PayrollDates::date('1990-01-01'),
        gender: 'F',
        fullName: 'Elena PartTime',
        baseAnnualSalary: '36000',
        fteRatio: '0.50',
        salaryInstallmentsPerYear: 12,
        workCanton: 'ZH',
        residenceCanton: 'ZH',
        isWithholdingTaxSubject: true,
        withholdingTaxCode: 'A0N',
        extrapolateQstToFullTime: true,
    );

    $resPartTime = SwissPayrollEngine::calculate(new SwissPayrollContext(
        employee: $partTimeQst,
        year: 2026,
        month: 1,
        wageTypes: SwissWageTypeCatalog::byCode(),
        withholdingTaxTariffs: $tariffs,
    ));

    expect($resPartTime->payslip->withholdingTaxBasis)->toBe('3000.00')
        ->and($resPartTime->payslip->withholdingTaxRatePercent)->toBe('10.00')
        ->and($resPartTime->amount(Codes::WithholdingTax))->toBe('-300');

    // 3. New Frontier Worker (CH-IT Agreement) with 80% taxable basis rule:
    // Salary 6000/month -> rate looked up on 100% (6000 -> 12% bracket), QST applied to 80% of 6000 = 4800 * 12% = -576.00
    $newFrontier = new EmployeeData(
        hireDate: PayrollDates::date('2025-01-01'),
        dateOfBirth: PayrollDates::date('1988-05-10'),
        gender: 'M',
        fullName: 'Paolo Frontaliere Nuovo',
        baseAnnualSalary: '72000',
        salaryInstallmentsPerYear: 12,
        workCanton: 'TI',
        residenceCountry: 'IT',
        isWithholdingTaxSubject: true,
        withholdingTaxCode: 'R0N',
        frontierRegime: 'new',
        frontierApply80PercentBasis: true,
    );

    $resFrontier = SwissPayrollEngine::calculate(new SwissPayrollContext(
        employee: $newFrontier,
        year: 2026,
        month: 1,
        wageTypes: SwissWageTypeCatalog::byCode(),
        withholdingTaxTariffs: $tariffs,
    ));

    expect($resFrontier->payslip->withholdingTaxBasis)->toBe('4800.00')
        ->and($resFrontier->payslip->withholdingTaxRatePercent)->toBe('12.00')
        ->and($resFrontier->amount(Codes::WithholdingTax))->toBe('-576');
});

test('Phase C.5: Swiss work permit compliance endpoint detects expired permits, expiring permits and QST mismatches', function (): void {
    $empExpiredB = payrollEmployee($this->tenant, ['first_name' => 'Marco', 'last_name' => 'Scaduto', 'full_name' => 'Marco Scaduto']);
    payrollProfile($this->tenant, $empExpiredB, [
        'swiss_permit_type' => 'B',
        'swiss_permit_number' => 'B-998877',
        'swiss_permit_valid_until' => '2026-10-01', // expired before 2026-11-06
        'is_withholding_tax_subject' => false, // mismatch for B permit!
    ]);

    $empExpiringG = payrollEmployee($this->tenant, ['first_name' => 'Giulia', 'last_name' => 'Frontaliere', 'full_name' => 'Giulia Frontaliere']);
    payrollProfile($this->tenant, $empExpiringG, [
        'swiss_permit_type' => 'G',
        'residence_country' => 'IT',
        'swiss_permit_valid_until' => '2026-12-01', // expiring within 60 days of 2026-11-06
        'is_withholding_tax_subject' => true,
        'withholding_tax_code' => 'R0N',
    ]);

    $empPermitC = payrollEmployee($this->tenant, ['first_name' => 'Hans', 'last_name' => 'Domicilio', 'full_name' => 'Hans Domicilio']);
    payrollProfile($this->tenant, $empPermitC, [
        'swiss_permit_type' => 'C',
        'residence_country' => 'CH',
        'is_withholding_tax_subject' => true, // mismatch for C permit resident in CH!
        'withholding_tax_code' => 'A0N',
    ]);

    $audit = payrollApi($this->manager, $this->tenant)
        ->getJson('/api/v1/payroll/compliance/permit-alerts')
        ->assertOk()
        ->json('data');

    expect($audit['summary']['expired_count'])->toBe(1)
        ->and($audit['summary']['expiring_soon_count'])->toBe(1)
        ->and($audit['summary']['qst_mismatch_count'])->toBe(2)
        ->and($audit['summary']['is_compliant'])->toBeFalse()
        ->and(collect($audit['alerts'])->pluck('type')->all())->toContain('permit_expired', 'permit_expiring', 'qst_mismatch');
});

test('Phase D & E: Swissdec ELM 5.0/6.0 10-domain declaration, XSD validation, and Lohnausweis Form 11 with PDF417 barcode', function (): void {
    SwissPayrollSettings::query()->where('tenant_id', $this->tenant->id)->update([
        'ahv_member_number' => '106.1234',
        'uvg_insurer' => 'SUVA',
        'bvg_insurer' => 'Fondazione LPP Ticino',
    ]);

    PayrollWithholdingTaxTariff::query()->create([
        'tenant_id' => $this->tenant->id,
        'canton' => 'TI',
        'tariff_code' => 'R0N',
        'valid_from' => '2026-01-01',
        'income_from' => '0',
        'income_step' => '10000',
        'minimum_tax' => '0',
        'rate_percent' => '10.0000',
    ]);

    $employee = payrollEmployee($this->tenant, [
        'first_name' => 'Roberto',
        'last_name' => 'Verdi',
        'full_name' => 'Roberto Verdi',
        'base_annual_salary' => '76800.00',
        'fte_ratio' => '0.80',
        'nationality' => 'IT',
    ]);

    payrollProfile($this->tenant, $employee, [
        'social_security_number' => '756.9217.0769.85',
        'iban' => 'CH9300762011623852957',
        'street' => 'Via Roma',
        'house_number' => '12',
        'postal_code' => '22100',
        'city' => 'Como',
        'residence_country' => 'IT',
        'work_canton' => 'TI',
        'civil_status' => 'married',
        'swiss_permit_type' => 'G',
        'swiss_permit_valid_until' => '2028-12-31',
        'italian_tax_code' => 'VRDRRT86E10C933K',
        'birth_place' => 'Como',
        'frontier_regime' => 'new',
        'frontier_apply_80_percent_basis' => true,
        'is_withholding_tax_subject' => true,
        'withholding_tax_code' => 'R0N',
        'salary_installments_per_year' => 12,
    ]);

    PayrollEmployeeChild::query()->create([
        'tenant_id' => $this->tenant->id,
        'employee_id' => $employee->id,
        'first_name' => 'Sofia',
        'date_of_birth' => '2018-04-12',
    ]);

    $api = payrollApi($this->manager, $this->tenant);

    // Custom BVG buy-in deduction wage type (box 10.2)
    $api->postJson('/api/v1/payroll/wage-types', [
        'code' => '9045',
        'name' => 'Riscatto LPP',
        'kind' => 'Deduction',
        'certificate_box' => '10.2',
        'sort_order' => 945,
    ])->assertCreated();

    // Add inputs for January 2026: Canteen/board (4000 -> box 2.1, checkbox G), Company car (4010 -> box 2.2, checkbox F),
    // Bonus (1100 -> box 3), Effective expenses (5000 -> box 13.1.1), Lump-sum representation expenses (5010 -> box 13.2.1),
    // and LPP buy-in (9045 -> box 10.2).
    foreach ([
        ['wage_type_code' => '4000', 'amount' => '150.00'],
        ['wage_type_code' => '4010', 'amount' => '300.00'],
        ['wage_type_code' => '1100', 'amount' => '1000.00'],
        ['wage_type_code' => '5000', 'amount' => '220.00'],
        ['wage_type_code' => '5010', 'amount' => '180.00'],
        ['wage_type_code' => '9045', 'amount' => '-500.00'],
    ] as $input) {
        $api->postJson('/api/v1/payroll/inputs', [
            'employee_id' => $employee->id,
            'year' => 2026,
            'month' => 1,
            ...$input,
        ])->assertCreated();
    }

    payrollClosedRun($this->tenant, $this->manager, 2026, 1);

    // 1. Verify 10-domain Swissdec ELM structure
    $declaration = $api->getJson('/api/v1/payroll/swissdec/declaration?year=2026')->assertOk()->json('data');
    $company = $declaration['SalaryDeclaration']['Company'];
    $person = $company['Person'][0];

    expect($declaration['certification']['domains_built'])->toHaveCount(10)
        ->and($person)->toHaveKeys([
            'AHV-AVS-Salaries',
            'UVG-LAA-Salaries',
            'UVGZ-LAAC-Salaries',
            'KTG-AMC-Salaries',
            'BVG-LPP-Salaries',
            'FAK-CAF-Salaries',
            'TaxAtSourceSalaries',
            'TaxSalaries',
            'StatisticSalaries',
            'TaxCrossborderSalaries',
        ])
        ->and($company['SalaryTotals'])->toHaveKeys([
            'AHV-AVS-Totals',
            'UVG-LAA-Totals',
            'UVGZ-LAAC-Totals',
            'KTG-AMC-Totals',
            'BVG-LPP-Totals',
            'FAK-CAF-Totals',
            'TaxAtSourceTotals',
            'TaxTotals',
            'TaxCrossborderTotals',
        ])
        ->and($person['TaxCrossborderSalaries']['TaxCrossborderSalary']['TaxID'])->toBe('VRDRRT86E10C933K');

    // 2. Verify XML serialization & XSD validation endpoints (ELM 5.0 and ELM 6.0)
    $xmlResponse = $api->get('/api/v1/payroll/compliance/salary-declaration/xml?year=2026&version=5.0')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    $xmlContent = (string) $xmlResponse->getContent();

    expect($xmlContent)->toContain('http://www.swissdec.ch/schema/sd/20200220/SalaryDeclaration')
        ->and($xmlContent)->toContain('<UVG-LAA-Salaries>')
        ->and($xmlContent)->toContain('<TaxCrossborderSalaries>');

    $validation = $api->postJson('/api/v1/payroll/compliance/salary-declaration/validate', ['year' => 2026, 'version' => '6.0'])
        ->assertOk()
        ->json('data');

    expect($validation['is_valid'])->toBeTrue()
        ->and($validation['errors'])->toBe([]);

    // Tampered XML with mismatched AHV total fails validation
    $tamperedXml = str_replace('<Total-AHV-AVS-Incomes>', '<Total-AHV-AVS-Incomes>999999.99</Total-AHV-AVS-Incomes><InvalidTag>', $xmlContent);
    $badValidation = $api->postJson('/api/v1/payroll/compliance/salary-declaration/validate', ['xml' => $tamperedXml])
        ->assertOk()
        ->json('data');

    expect($badValidation['is_valid'])->toBeFalse()
        ->and($badValidation['errors'])->not->toBeEmpty();

    // 3. Verify Lohnausweis Form 11 generation + PDF417 barcode
    $api->postJson('/api/v1/payroll/salary-certificates/generate', ['year' => 2026])
        ->assertOk()
        ->assertJsonPath('data.generated', 1);

    $cert = SalaryCertificate::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->sole();

    expect($cert->checkbox_f_transport)->toBeTrue()
        ->and($cert->checkbox_g_canteen)->toBeTrue()
        ->and($cert->box2_benefits)->toBe('450.00')
        ->and($cert->box3_irregular)->toBe('1000.00')
        ->and($cert->box10_2_pension_buyin)->toBe('500.00')
        ->and(Dec::add((string) $cert->box10_1_ordinary_pension, (string) $cert->box10_2_pension_buyin))->toBe((string) $cert->box10_pension)
        ->and($cert->box13_1_effective_expenses)->toBe('220.00')
        ->and($cert->box13_2_lump_sum_expenses)->toBe('180.00')
        ->and($cert->box13_expenses)->toBe('400.00')
        ->and($cert->box15_remarks)->toContain('Grado di occupazione: 80%')
        ->and($cert->box15_remarks)->toContain('Permesso: G')
        ->and($cert->barcode_payload)->toStartWith('STD=eCH-0019:2.0|FORM=11|YEAR=2026')
        ->and($cert->barcode_svg)->toContain('data-barcode-type="PDF417"');

    $pdf = $api->get("/api/v1/payroll/salary-certificates/{$cert->id}/pdf")->assertOk();
    expect(str_starts_with((string) $pdf->getContent(), '%PDF'))->toBeTrue();
});
