<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Payroll\Models\ItalianPayrollSettings;
use Modules\Payroll\Models\PayrollEmployeeChild;
use Modules\Payroll\Models\SalaryCertificate;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
});

/**
 * @return array{0: mixed, 1: mixed, 2: mixed}
 */
function payrollItalianCompany(): array
{
    $tenant = payrollTenant('IT');
    $manager = payrollMember($tenant, 'owner');
    $employee = payrollEmployee($tenant, ['base_annual_salary' => '30000.00', 'tax_code' => 'RSSMRA86E10H501Z']);
    payrollProfile($tenant, $employee, [
        'social_security_number' => 'RSSMRA86E10H501Z',
        'regional_surcharge_rate_percent' => '1.73',
        'municipal_surcharge_rate_percent' => '0.8',
        'region_code' => '09',
        'municipality_code' => 'H501',
        'inps_qualification' => '1',
        'collective_agreement_code' => 'H011',
        'contract_level' => '4',
    ]);
    ItalianPayrollSettings::query()->create(['tenant_id' => $tenant->id, 'inps_company_number' => '1234567890', 'inps_office_code' => '5100', 'inail_code' => '998']);

    payrollCalculate($tenant, $manager, 2025, 12)->assertCreated();
    payrollCalculate($tenant, $manager, 2026, 3)->assertCreated();

    return [$tenant, $manager, $employee];
}

test('the Swiss declaration reads the closed runs only and totals them', function (): void {
    $tenant = payrollTenant();
    $manager = payrollMember($tenant, 'admin');
    $employee = payrollEmployee($tenant);
    payrollProfile($tenant, $employee, ['social_security_number' => '756.1234.5678.97', 'work_canton' => 'TI']);
    payrollSwissSetup($tenant, $manager);

    payrollApi($manager, $tenant)->getJson('/api/v1/payroll/exports/salary-declaration?year=2026')
        ->assertUnprocessable()
        ->assertJsonPath('detail', "Nessuna elaborazione chiusa nell'anno.");

    payrollClosedRun($tenant, $manager, 2026, 1);
    payrollClosedRun($tenant, $manager, 2026, 2);
    payrollCalculate($tenant, $manager, 2026, 3)->assertCreated();

    $response = payrollApi($manager, $tenant)->get('/api/v1/payroll/exports/salary-declaration?year=2026')->assertOk();
    $lines = explode("\r\n", substr((string) $response->getContent(), 3));

    expect(str_starts_with((string) $response->getContent(), "\xEF\xBB\xBF"))->toBeTrue()
        ->and($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain('Dichiarazione_salari_2026.csv')
        ->and(rawurldecode((string) $response->headers->get('X-Filing-Summary')))->toContain('Swissdec')
        ->and($lines[0])->toStartWith('N. AVS;Cognome;Nome;Data di nascita;Sesso;Dal;Al;Salario lordo')
        ->and($lines[1])->toBe('756.1234.5678.97;Rossi;Mario;1986-05-10;M;2026-01-01;2026-02-28;13000.00;13000.00;13000.00;13000.00;13000.00;58040.00;0.00;TI')
        ->and($lines[2])->toBe('Totale;;;;;;;13000.00;13000.00;13000.00;13000.00;13000.00;;0.00;');
});

test('salary certificates are generated from the closed runs and replaced when generated again', function (): void {
    $tenant = payrollTenant();
    $manager = payrollMember($tenant, 'admin');
    $worker = payrollMember($tenant, 'member');
    $employee = payrollEmployee($tenant, ['hire_date' => '2026-02-01'], $worker);
    payrollProfile($tenant, $employee);
    payrollSwissSetup($tenant, $manager);

    payrollApi($manager, $tenant)->postJson('/api/v1/payroll/salary-certificates/generate', ['year' => 2026])
        ->assertUnprocessable()
        ->assertJsonPath('detail', 'Nessuna elaborazione chiusa nel 2026.');

    payrollClosedRun($tenant, $manager, 2026, 2);
    payrollClosedRun($tenant, $manager, 2026, 3);

    payrollApi($manager, $tenant)->postJson('/api/v1/payroll/salary-certificates/generate', ['year' => 2026])->assertOk()->assertJsonPath('data.generated', 1);
    payrollApi($manager, $tenant)->postJson('/api/v1/payroll/salary-certificates/generate', ['year' => 2026])->assertOk()->assertJsonPath('data.generated', 1);

    $certificate = SalaryCertificate::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    expect($certificate->period_from->format('Y-m-d'))->toBe('2026-02-01')
        ->and($certificate->period_to->format('Y-m-d'))->toBe('2026-12-31')
        ->and($certificate->box1_salary)->toBe('13000.00')
        ->and($certificate->box8_gross)->toBe('13000.00')
        ->and($certificate->box9_social_contributions)->toBe('988.00')
        ->and($certificate->box10_pension)->toBe('483.70')
        ->and($certificate->box11_net)->toBe('11528.30');

    payrollApi($worker, $tenant)->getJson('/api/v1/payroll/salary-certificates')->assertOk()->assertJsonCount(1, 'data');
    $pdf = payrollApi($worker, $tenant)->get("/api/v1/payroll/salary-certificates/{$certificate->id}/pdf")->assertOk();

    expect(str_starts_with((string) $pdf->getContent(), '%PDF'))->toBeTrue();

    $stranger = payrollMember($tenant, 'member');
    payrollApi($stranger, $tenant)->getJson("/api/v1/payroll/salary-certificates/{$certificate->id}")->assertForbidden();
});

test('the family allowance lines are paid out through the Swiss payslip PDF', function (): void {
    $tenant = payrollTenant();
    $manager = payrollMember($tenant, 'admin');
    $employee = payrollEmployee($tenant);
    payrollProfile($tenant, $employee, ['iban' => 'CH9300762011623852957']);
    PayrollEmployeeChild::query()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'first_name' => 'Luca', 'date_of_birth' => '2020-01-01']);
    payrollSwissSetup($tenant, $manager);
    $run = payrollClosedRun($tenant, $manager, 2026, 1);
    $slip = $run->payslips()->withoutGlobalScopes()->sole();

    expect($slip->gross_salary)->toBe('6715.00');

    $pdf = payrollApi($manager, $tenant)->get("/api/v1/payroll/payslips/{$slip->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

    expect(str_starts_with((string) $pdf->getContent(), '%PDF'))->toBeTrue();
});

test('the F24 of a month has the rows of the desktop', function (): void {
    [$tenant, $manager] = payrollItalianCompany();

    $response = payrollApi($manager, $tenant)->get('/api/v1/payroll/exports/f24?year=2026&month=3')->assertOk();
    $lines = explode("\r\n", substr((string) $response->getContent(), 3));
    $summary = rawurldecode((string) $response->headers->get('X-Filing-Summary'));

    expect($lines[0])->toBe('Sezione;Codice tributo / causale;Codice ente / sede / regione;Matricola / rateazione;Periodo / anno di riferimento;Importo a debito;Importo a credito')
        ->and($lines[1])->toStartWith('Erario;1001;;;2026;')
        ->and($lines[2])->toStartWith('Regioni;3802;09;0311;2025;')
        ->and($lines[3])->toStartWith('IMU e altri tributi locali;3848;H501;0311;2025;')
        ->and($lines[4])->toStartWith('INPS;DM10;5100;1234567890;032026;')
        ->and($summary)->toContain('F24 da versare entro il 16.04.2026')
        ->and($response->headers->get('Content-Disposition'))->toContain('F24_paghe_2026-03.csv');
});

test('the F24 refuses a month without payslips', function (): void {
    [$tenant, $manager] = payrollItalianCompany();

    payrollApi($manager, $tenant)->getJson('/api/v1/payroll/exports/f24?year=2026&month=4')
        ->assertUnprocessable()
        ->assertJsonPath('detail', 'Nessuna busta paga elaborata nel periodo.');
});

test('the UniEmens data give one row per payslip with the weeks of the desktop', function (): void {
    [$tenant, $manager] = payrollItalianCompany();

    $lines = explode("\r\n", substr((string) payrollApi($manager, $tenant)->get('/api/v1/payroll/exports/uniemens?year=2026&month=3')->assertOk()->getContent(), 3));

    expect($lines[0])->toStartWith('Matricola azienda;Periodo;Codice fiscale')
        ->and($lines[1])->toBe('1234567890;2026-03;RSSMRA86E10H501Z;Rossi;Mario;1;H011;4;2308.00;212.11;669.32;26;5;2020-01-01;');
});

test('the CU data sum the year', function (): void {
    [$tenant, $manager] = payrollItalianCompany();

    $lines = explode("\r\n", substr((string) payrollApi($manager, $tenant)->get('/api/v1/payroll/exports/cu?year=2026')->assertOk()->getContent(), 3));

    expect($lines[0])->toStartWith('Codice fiscale;Cognome;Nome;Periodo dal;Periodo al;Punto 1 - Redditi di lavoro dipendente')
        ->and($lines[1])->toStartWith('RSSMRA86E10H501Z;Rossi;Mario;2026-03-01;2026-03-31;2095.58;');
});

test('the labour book is a PDF of the run', function (): void {
    [$tenant, $manager] = payrollItalianCompany();
    $run = payrollApi($manager, $tenant)->getJson('/api/v1/payroll/runs?year=2026')->assertOk()->json('data.0.id');

    $pdf = payrollApi($manager, $tenant)->get("/api/v1/payroll/runs/{$run}/labour-book")->assertOk()->assertHeader('Content-Type', 'application/pdf');

    expect(str_starts_with((string) $pdf->getContent(), '%PDF'))->toBeTrue()
        ->and($pdf->headers->get('Content-Disposition'))->toContain('LUL_2026-03.pdf');
});

test('each filing belongs to its country', function (): void {
    $swiss = payrollTenant();
    $swissManager = payrollMember($swiss, 'owner');
    [$italian, $italianManager] = payrollItalianCompany();

    payrollApi($swissManager, $swiss)->getJson('/api/v1/payroll/exports/f24?year=2026&month=3')->assertUnprocessable();
    payrollApi($swissManager, $swiss)->getJson('/api/v1/payroll/exports/uniemens?year=2026&month=3')->assertUnprocessable();
    payrollApi($swissManager, $swiss)->getJson('/api/v1/payroll/exports/cu?year=2026')->assertUnprocessable();
    payrollApi($italianManager, $italian)->getJson('/api/v1/payroll/exports/salary-declaration?year=2026')->assertUnprocessable();
    payrollApi($italianManager, $italian)->postJson('/api/v1/payroll/salary-certificates/generate', ['year' => 2026])->assertUnprocessable();
    payrollApi($italianManager, $italian)->postJson('/api/v1/payroll/setup')->assertUnprocessable();
});
