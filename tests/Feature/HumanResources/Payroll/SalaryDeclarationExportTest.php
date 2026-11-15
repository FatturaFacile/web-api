<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Contracts\Payroll\PayrollRunServiceInterface;
use Modules\HumanResources\DTOs\Payroll\CreatePayrollRunDTO;
use Modules\HumanResources\Models\Payroll\PayrollRun;
use Modules\HumanResources\Models\Payroll\Payslip;
use Modules\HumanResources\Models\Payroll\SalaryDeclaration;
use Modules\HumanResources\Models\Payroll\WithholdingTariff;
use Modules\HumanResources\Services\Payroll\Rappen;
use Tests\Feature\HumanResources\Payroll\PayrollFixtures;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    Storage::fake('private');
    config(['payroll_ch.declaration_disk' => 'private', 'payroll_ch.elm_xsd_path' => null]);
});

test('a declaration of closed runs is well-formed ELM-style XML whose totals and personal data match the payroll', function (): void {
    [$tenant, $user] = PayrollFixtures::tenantWithUser();
    PayrollFixtures::referenceData($tenant);
    $swissResident = PayrollFixtures::employee($tenant, employee: ['employee_number' => 'EMP-CH-001', 'first_name' => 'Anna', 'last_name' => 'Muster']);
    PayrollFixtures::employee($tenant, employee: ['employee_number' => 'EMP-TI-002'], profile: [
        'ahv_number' => '756.1234.5678.97',
        'work_canton' => 'TI',
        'permit_type' => 'B',
        'qst_tariff_code' => 'A0N',
        'qst_canton' => 'TI',
    ]);
    WithholdingTariff::factory()->flatRate('10.00')->create(['tenant_id' => $tenant->id, 'canton' => 'TI', 'tariff_code' => 'A0N', 'valid_from' => '2026-01-01']);

    $service = app(PayrollRunServiceInterface::class);

    foreach ([1, 2] as $month) {
        $run = $service->createRun(new CreatePayrollRunDTO((string) $tenant->id, (string) $tenant->id, 2026, $month));
        $service->close($service->approve($service->calculate($run)));
    }

    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson('/api/v1/hr/payroll/declarations', ['year' => 2026])
        ->assertCreated()
        ->assertJsonPath('data.period_year', 2026)
        ->assertJsonPath('data.employee_count', 2)
        ->assertJsonPath('data.schema_version', '5.0')
        ->assertJsonPath('data.xsd_validated', false);

    $declaration = SalaryDeclaration::query()->findOrFail($response->json('data.id'));
    $xml = Storage::disk('private')->get($declaration->xml_path);

    expect($xml)->not->toBeNull()
        ->and(hash('sha256', (string) $xml))->toBe($declaration->xml_sha256)
        ->and($response->json('data.xml_sha256'))->toBe($declaration->xml_sha256);

    $document = new DOMDocument;
    expect($document->loadXML((string) $xml))->toBeTrue();

    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('sd', (string) config('payroll_ch.elm_namespace'));

    $payslips = Payslip::query()->where('tenant_id', $tenant->id)->get();

    expect($payslips)->toHaveCount(4)
        ->and($xpath->evaluate('count(//sd:Staff/sd:Person)'))->toEqual(2)
        ->and($xpath->evaluate('count(//sd:Salaries/sd:Salary)'))->toEqual(4)
        ->and($xpath->evaluate('string(/sd:SalaryDeclaration/sd:Totals/sd:GrossSalary)'))->toBe(Rappen::toDecimal((int) $payslips->sum('gross_rappen')))
        ->and($xpath->evaluate('string(/sd:SalaryDeclaration/sd:Totals/sd:NetSalary)'))->toBe(Rappen::toDecimal((int) $payslips->sum('net_rappen')))
        ->and($xpath->evaluate('string(/sd:SalaryDeclaration/sd:Totals/sd:TaxAtSource)'))->toBe(Rappen::toDecimal((int) $payslips->sum('withholding_tax_rappen')))
        ->and($declaration->gross_rappen)->toBe((int) $payslips->sum('gross_rappen'))
        ->and($declaration->net_rappen)->toBe((int) $payslips->sum('net_rappen'));

    $anna = '//sd:Person[sd:Particulars/sd:EmployeeNumber="EMP-CH-001"]';
    expect($xpath->evaluate("string({$anna}/sd:Particulars/sd:Social-InsuranceIdentification/sd:SV-AS-Number)"))->toBe('756.9217.0769.85')
        ->and($xpath->evaluate("string({$anna}/sd:Particulars/sd:Lastname)"))->toBe('Muster')
        ->and($xpath->evaluate("string({$anna}/sd:Particulars/sd:DateOfBirth)"))->toBe('1986-03-10')
        ->and($xpath->evaluate("string({$anna}/sd:Work/sd:WorkplaceCanton)"))->toBe('ZH')
        ->and($xpath->evaluate("string({$anna}/sd:Salaries/sd:Salary[1]/sd:Deductions/sd:AHV-AVS-Contribution)"))->toBe('424.00')
        ->and($xpath->evaluate("count({$anna}/sd:TaxAtSource)"))->toEqual(0);

    $commuter = '//sd:Person[sd:Particulars/sd:EmployeeNumber="EMP-TI-002"]';
    expect($xpath->evaluate("string({$commuter}/sd:Particulars/sd:Social-InsuranceIdentification/sd:SV-AS-Number)"))->toBe('756.1234.5678.97')
        ->and($xpath->evaluate("string({$commuter}/sd:TaxAtSource/sd:TaxAtSourceCanton)"))->toBe('TI')
        ->and($xpath->evaluate("string({$commuter}/sd:TaxAtSource/sd:TaxAtSourceCategory)"))->toBe('A0N')
        ->and($xpath->evaluate("string({$commuter}/sd:Salaries/sd:Salary[1]/sd:Deductions/sd:TaxAtSource)"))->toBe('800.00');

    $this->getJson("/api/v1/hr/payroll/declarations/{$declaration->id}")
        ->assertOk()
        ->assertJsonPath('data.declaration_number', $declaration->declaration_number);

    $download = $this->get("/api/v1/hr/payroll/declarations/{$declaration->id}/download");
    $download->assertOk()->assertHeader('X-Content-SHA256', $declaration->xml_sha256);

    expect($download->streamedContent())->toBe($xml);
});

test('the declaration is rejected with 422 when an employee lacks an AHV number', function (): void {
    [$tenant, $user] = PayrollFixtures::tenantWithUser();
    PayrollFixtures::referenceData($tenant);
    $employee = PayrollFixtures::employee($tenant, profile: ['ahv_number' => null]);

    $service = app(PayrollRunServiceInterface::class);
    $service->close($service->approve($service->calculate(
        $service->createRun(new CreatePayrollRunDTO((string) $tenant->id, (string) $tenant->id, 2026, 1)),
    )));

    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson('/api/v1/hr/payroll/declarations', ['year' => 2026]);

    $response->assertStatus(422)->assertJsonPath('title', 'Salary Declaration Not Possible');
    expect($response->json('invalid_params'))->toHaveKey("employees.{$employee->id}.ahv_number")
        ->and(SalaryDeclaration::query()->count())->toBe(0);
});

test('only approved or closed runs of the year can be declared', function (): void {
    [$tenant, $user] = PayrollFixtures::tenantWithUser();
    $draft = PayrollRun::factory()->forPeriod(2026, 1)->create(['tenant_id' => $tenant->id]);
    Sanctum::actingAs($user, ['*']);

    $this->postJson('/api/v1/hr/payroll/declarations', ['year' => 2026])->assertStatus(422);

    $response = $this->postJson('/api/v1/hr/payroll/declarations', ['year' => 2026, 'payroll_run_ids' => [$draft->id]]);

    $response->assertStatus(422);
    expect($response->json('invalid_params'))->toHaveKey('payroll_run_ids');
});
