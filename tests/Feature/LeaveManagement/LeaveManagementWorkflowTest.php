<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\LeaveManagementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Employee;
use Modules\LeaveManagement\Domain\Enums\LeaveTypeCode;

uses(RefreshDatabase::class);

function lmTenantSetup(string $country = 'CH'): array
{
    $tenant = Tenant::create([
        'name' => 'Mod074 Org '.Str::random(4),
        'legal_name' => 'Mod074 Org SA',
        'country' => $country,
        'default_currency' => $country === 'CH' ? 'CHF' : 'EUR',
    ]);

    $hrUser = User::create([
        'name' => 'HR Lead',
        'email' => Str::random(10).'@example.ch',
        'password' => bcrypt(Str::password(16)),
        'locale' => 'it',
    ]);
    $hrUser->tenants()->attach($tenant->id, ['id' => (string) Str::uuid7(), 'role' => 'hr']);

    $memberUser = User::create([
        'name' => 'Employee Member',
        'email' => Str::random(10).'@example.ch',
        'password' => bcrypt(Str::password(16)),
        'locale' => 'it',
    ]);
    $memberUser->tenants()->attach($tenant->id, ['id' => (string) Str::uuid7(), 'role' => 'member']);

    app(LeaveManagementSeeder::class)->seedForTenant((string) $tenant->id, 2026);

    return [$tenant, $hrUser, $memberUser];
}

function lmActingAs(User $user, Tenant $tenant): mixed
{
    Sanctum::actingAs($user);

    return test()->withHeader('X-Tenant-Id', (string) $tenant->id);
}

test('full Mod 074 workflow: holiday catalogs, monthly pro-rata accruals, staffing guard, INPS PUC, SUVA, comporto, Art. 329b CO, and Gantt calendar', function (): void {
    [$tenant, $hrUser, $memberUser] = lmTenantSetup('CH');

    $dept = Department::query()->create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $tenant->id,
        'code' => 'ENG',
        'name' => 'Engineering',
        'is_active' => true,
    ]);

    // CH employee born 1970 (over 50 => Art. 329a CO 25 days) at 80% FTE
    $chEmployee = Employee::factory()->active()->create([
        'tenant_id' => (string) $tenant->id,
        'user_id' => (string) $memberUser->id,
        'department_id' => (string) $dept->id,
        'birth_date' => '1970-04-10',
        'hire_date' => '2025-01-01',
        'fte_ratio' => '0.80',
        'currency' => 'CHF',
        'nationality' => 'CH',
    ]);

    // IT employee full-time
    $itEmployee = Employee::factory()->active()->create([
        'tenant_id' => (string) $tenant->id,
        'department_id' => (string) $dept->id,
        'birth_date' => '1992-05-15',
        'hire_date' => '2025-01-01',
        'fte_ratio' => '1.00',
        'currency' => 'EUR',
        'nationality' => 'IT',
    ]);

    // 1. Official holiday catalog check
    lmActingAs($hrUser, $tenant)
        ->getJson('/api/v1/leave-management/holidays/catalog?country=CH&year=2026&canton_or_province_code=TI')
        ->assertOk()
        ->assertJsonPath('data.country', 'CH');

    // 2. Calculate monthly accruals (2026-01): CH over-50 80% FTE = 25 * 0.80 / 12 = 1.67d
    lmActingAs($hrUser, $tenant)
        ->postJson('/api/v1/leave-management/ledgers/calculate-monthly', [
            'fiscal_year' => 2026,
            'month' => 1,
        ])
        ->assertOk();

    $chLedgers = lmActingAs($memberUser, $tenant)
        ->getJson('/api/v1/leave-management/ledgers?fiscal_year=2026')
        ->assertOk()
        ->json('data');

    expect($chLedgers)->toHaveCount(1)
        ->and((float) $chLedgers[0]['annual_entitlement_target'])->toBe(20.0)
        ->and((float) $chLedgers[0]['accrued_ytd'])->toBe(1.67);

    // 3. Configure department minimum staffing threshold: require at least 2 present with hard block
    lmActingAs($hrUser, $tenant)
        ->putJson("/api/v1/leave-management/staffing-thresholds/{$dept->id}", [
            'min_present_count' => 2,
            'min_present_percent' => 50,
            'enforce_hard_block' => true,
        ])
        ->assertOk();

    // Since department has 2 active employees, any request leaves 1 present (< 2 required) => hard block 409
    lmActingAs($memberUser, $tenant)
        ->postJson('/api/v1/leave-management/requests', [
            'employee_id' => (string) $chEmployee->id,
            'country' => 'CH',
            'leave_type' => LeaveTypeCode::AnnualVacation_Ferie_Ferien->value,
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-15',
            'half_day_or_hourly_duration' => 'morning',
        ])
        ->assertConflict();

    // Relax hard block and submit half-day request
    lmActingAs($hrUser, $tenant)
        ->putJson("/api/v1/leave-management/staffing-thresholds/{$dept->id}", [
            'min_present_count' => 1,
            'min_present_percent' => 50,
            'enforce_hard_block' => false,
        ])
        ->assertOk();

    $reqId = (string) lmActingAs($memberUser, $tenant)
        ->postJson('/api/v1/leave-management/requests', [
            'employee_id' => (string) $chEmployee->id,
            'country' => 'CH',
            'leave_type' => LeaveTypeCode::AnnualVacation_Ferie_Ferien->value,
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-15',
            'half_day_or_hourly_duration' => 'morning',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending_approval')
        ->json('data.id');

    lmActingAs($hrUser, $tenant)
        ->postJson("/api/v1/leave-management/requests/{$reqId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    // 4. Register IT sick leave with invalid vs valid 9-char INPS PUC
    lmActingAs($hrUser, $tenant)
        ->postJson('/api/v1/leave-management/requests/sick-inps', [
            'employee_id' => (string) $itEmployee->id,
            'country' => 'IT',
            'leave_type' => LeaveTypeCode::SickLeave_Malattia->value,
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-25',
            'inps_medical_certificate_protocol_number' => 'BAD',
        ])
        ->assertUnprocessable();

    lmActingAs($hrUser, $tenant)
        ->postJson('/api/v1/leave-management/requests/sick-inps', [
            'employee_id' => (string) $itEmployee->id,
            'country' => 'IT',
            'leave_type' => LeaveTypeCode::SickLeave_Malattia->value,
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-25',
            'inps_medical_certificate_protocol_number' => 'PUC123456',
            'auto_approve' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.inps_medical_certificate_protocol_number', 'PUC123456')
        ->assertJsonPath('data.indemnity_breakdown.employer_waiting_days', 3)
        ->assertJsonPath('data.indemnity_breakdown.tier1_insurer_days', 17);

    // 5. Register 70-day CH sick leave (triggers Art. 329b CO Ferienkürzung: 2 full months -> 1 month after 1-month grace -> 1/12 reduction)
    lmActingAs($hrUser, $tenant)
        ->postJson('/api/v1/leave-management/requests/sick-inps', [
            'employee_id' => (string) $chEmployee->id,
            'country' => 'CH',
            'leave_type' => LeaveTypeCode::SickLeave_Malattia->value,
            'start_date' => '2026-02-01',
            'end_date' => '2026-04-11',
            'ijm_ktg_waiting_days' => 30,
            'auto_approve' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.indemnity_breakdown.regime', 'ch_ijm_ktg');

    // Evaluate Art. 329b CO curtailment & apply to CH ledger
    lmActingAs($hrUser, $tenant)
        ->getJson("/api/v1/leave-management/comporto-curtailment?employee_id={$chEmployee->id}&fiscal_year=2026&country=CH&apply_curtailment=1")
        ->assertOk()
        ->assertJsonPath('data.swiss_curtailment.reducible_months', 1)
        ->assertJsonPath('data.swiss_curtailment.applied_to_ledger', true);

    // 6. Department Gantt & Payroll Summary feed
    lmActingAs($hrUser, $tenant)
        ->getJson("/api/v1/leave-management/department-calendar?from=2026-06-01&to=2026-06-30&department_id={$dept->id}&country=CH&canton_or_province_code=TI")
        ->assertOk()
        ->assertJsonPath('data.total_employees', 2);

    lmActingAs($hrUser, $tenant)
        ->getJson('/api/v1/leave-management/integrations/payroll-summary?year=2026&month=3&country=IT')
        ->assertOk();
});
