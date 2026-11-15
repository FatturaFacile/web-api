<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Models\Payroll\PayrollProfile;
use Modules\HumanResources\Models\Payroll\PayrollRun;
use Modules\HumanResources\Models\Payroll\Payslip;
use Modules\HumanResources\Models\Payroll\SalaryDeclaration;
use Tests\Feature\HumanResources\Payroll\PayrollFixtures;

uses(DatabaseTransactions::class);

test('records of another tenant are not found (404) for runs, payslips, profiles and declarations', function (): void {
    [$tenantB] = PayrollFixtures::tenantWithUser();
    $employeeB = PayrollFixtures::employee($tenantB);
    $runB = PayrollRun::factory()->forPeriod(2026, 1)->closed()->create(['tenant_id' => $tenantB->id]);
    $payslipB = Payslip::factory()->create(['tenant_id' => $tenantB->id, 'payroll_run_id' => $runB->id, 'employee_id' => $employeeB->id]);
    $declarationB = SalaryDeclaration::factory()->create(['tenant_id' => $tenantB->id]);

    [, $userA] = PayrollFixtures::tenantWithUser('admin');
    Sanctum::actingAs($userA, ['*']);

    $this->getJson("/api/v1/hr/payroll/runs/{$runB->id}")->assertNotFound();
    $this->getJson("/api/v1/hr/payroll/runs/{$runB->id}/payslips")->assertNotFound();
    $this->postJson("/api/v1/hr/payroll/runs/{$runB->id}/calculate")->assertNotFound();
    $this->getJson("/api/v1/hr/payroll/payslips/{$payslipB->id}")->assertNotFound();
    $this->getJson("/api/v1/hr/payroll/employees/{$employeeB->id}/profile")->assertNotFound();
    $this->putJson("/api/v1/hr/payroll/employees/{$employeeB->id}/profile", ['work_canton' => 'ZH'])->assertNotFound();
    $this->getJson("/api/v1/hr/payroll/declarations/{$declarationB->id}")->assertNotFound();
    $this->getJson("/api/v1/hr/payroll/declarations/{$declarationB->id}/download")->assertNotFound();

    $this->getJson('/api/v1/hr/payroll/runs')->assertOk()->assertJsonPath('meta.total', 0);
});

test('members without payroll permissions are denied (403)', function (): void {
    [$tenant] = PayrollFixtures::tenantWithUser();
    $employee = PayrollFixtures::employee($tenant);
    $run = PayrollRun::factory()->forPeriod(2026, 1)->create(['tenant_id' => $tenant->id]);
    $member = PayrollFixtures::member($tenant, 'member', ['hr.employees.view']);
    Sanctum::actingAs($member, ['*']);

    $this->getJson('/api/v1/hr/payroll/runs')->assertForbidden();
    $this->getJson("/api/v1/hr/payroll/runs/{$run->id}")->assertForbidden();
    $this->postJson('/api/v1/hr/payroll/runs', ['period_year' => 2026, 'period_month' => 2])->assertForbidden();
    $this->postJson("/api/v1/hr/payroll/runs/{$run->id}/calculate")->assertForbidden();
    $this->getJson('/api/v1/hr/payroll/wage-types')->assertForbidden();
    $this->getJson('/api/v1/hr/payroll/rate-sets')->assertForbidden();
    $this->getJson("/api/v1/hr/payroll/employees/{$employee->id}/profile")->assertForbidden();
    $this->putJson("/api/v1/hr/payroll/employees/{$employee->id}/profile", ['work_canton' => 'ZH'])->assertForbidden();
    $this->postJson('/api/v1/hr/payroll/declarations', ['year' => 2026])->assertForbidden();
});

test('hr.payroll.view grants read access but not management', function (): void {
    [$tenant] = PayrollFixtures::tenantWithUser();
    $run = PayrollRun::factory()->forPeriod(2026, 1)->create(['tenant_id' => $tenant->id]);
    $viewer = PayrollFixtures::member($tenant, 'accountant', ['hr.payroll.view']);
    Sanctum::actingAs($viewer, ['*']);

    $this->getJson('/api/v1/hr/payroll/runs')->assertOk();
    $this->getJson("/api/v1/hr/payroll/runs/{$run->id}")->assertOk();
    $this->postJson('/api/v1/hr/payroll/runs', ['period_year' => 2026, 'period_month' => 2])->assertForbidden();
    $this->postJson("/api/v1/hr/payroll/runs/{$run->id}/calculate")->assertForbidden();
    $this->postJson("/api/v1/hr/payroll/runs/{$run->id}/approve")->assertForbidden();
});

test('hr.payroll.manage and wildcard permissions grant payroll management', function (array $permissions): void {
    [$tenant] = PayrollFixtures::tenantWithUser();
    $manager = PayrollFixtures::member($tenant, 'member', $permissions);
    Sanctum::actingAs($manager, ['*']);

    $this->postJson('/api/v1/hr/payroll/runs', ['period_year' => 2026, 'period_month' => 2])->assertCreated();
    $this->getJson('/api/v1/hr/payroll/runs')->assertOk();
})->with([
    'explicit' => [['hr.payroll.manage']],
    'payroll wildcard' => [['hr.payroll.*']],
    'hr wildcard' => [['hr.*']],
    'global wildcard' => [['*']],
]);

test('the payroll profile stores the AHV number encrypted, validates its checksum and only exposes it masked', function (): void {
    [$tenant, $user] = PayrollFixtures::tenantWithUser();
    $employee = PayrollFixtures::employee($tenant);
    PayrollProfile::query()->where('employee_id', $employee->id)->forceDelete();
    Sanctum::actingAs($user, ['*']);

    $this->putJson("/api/v1/hr/payroll/employees/{$employee->id}/profile", [
        'ahv_number' => '756.9217.0769.84',
        'work_canton' => 'TI',
    ])->assertStatus(422)->assertJsonValidationErrors('ahv_number');

    $this->putJson("/api/v1/hr/payroll/employees/{$employee->id}/profile", [
        'ahv_number' => '7569217076985',
        'work_canton' => 'TI',
        'permit_type' => 'B',
        'civil_status' => 'married',
        'children_count' => 2,
        'qst_tariff_code' => 'C2N',
        'qst_canton' => 'TI',
        'uvg_class' => 'A1',
    ])->assertOk()
        ->assertJsonPath('data.ahv_number_masked', '756.****.****.85')
        ->assertJsonPath('data.is_subject_to_withholding_tax', true)
        ->assertJsonMissingPath('data.ahv_number');

    $profile = PayrollProfile::query()->where('employee_id', $employee->id)->firstOrFail();
    $rawValue = DB::table('hr_ch_payroll_profiles')->where('id', $profile->id)->value('ahv_number');

    expect($profile->ahv_number)->toBe('756.9217.0769.85')
        ->and($rawValue)->not->toContain('9217');

    $this->getJson("/api/v1/hr/payroll/employees/{$employee->id}/profile")
        ->assertOk()
        ->assertJsonPath('data.work_canton', 'TI')
        ->assertJsonPath('data.children_count', 2);
});
