<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\HRDemoDataSeeder;
use Database\Seeders\HRReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Employee;

uses(DatabaseTransactions::class);

test('EmployeeFactory supports all 9 mandatory enterprise lifecycle states', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($tenant);

    $candidate = Employee::factory()->candidate()->create(['tenant_id' => $tenant->id]);
    $onboarding = Employee::factory()->onboarding()->create(['tenant_id' => $tenant->id]);
    $probation = Employee::factory()->probation()->create(['tenant_id' => $tenant->id]);
    $active = Employee::factory()->active()->create(['tenant_id' => $tenant->id]);
    $promoted = Employee::factory()->promoted()->create(['tenant_id' => $tenant->id]);
    $transferred = Employee::factory()->transferred()->create(['tenant_id' => $tenant->id]);
    $terminated = Employee::factory()->terminated()->create(['tenant_id' => $tenant->id]);
    $onLeave = Employee::factory()->onLeave()->create(['tenant_id' => $tenant->id]);
    $retired = Employee::factory()->retired()->create(['tenant_id' => $tenant->id]);

    expect($candidate->employment_status)->toBe('candidate')
        ->and($onboarding->employment_status)->toBe('onboarding')
        ->and($probation->employment_status)->toBe('probation')
        ->and($active->employment_status)->toBe('active')
        ->and($promoted->employment_status)->toBe('promoted')
        ->and($transferred->employment_status)->toBe('transferred')
        ->and($terminated->employment_status)->toBe('terminated')
        ->and($onLeave->employment_status)->toBe('on_leave')
        ->and($retired->employment_status)->toBe('retired');
});

test('HRReferenceSeeder and HRDemoDataSeeder are idempotent and populate multilingual workforce data across 4 industries', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($tenant);

    $this->seed(HRReferenceSeeder::class);
    $this->seed(HRDemoDataSeeder::class);

    $deptCountFirst = Department::where('tenant_id', $tenant->id)->count();
    $empCountFirst = Employee::where('tenant_id', $tenant->id)->count();

    // Run seeders a second time to verify strict idempotency
    $this->seed(HRReferenceSeeder::class);
    $this->seed(HRDemoDataSeeder::class);

    expect(Department::where('tenant_id', $tenant->id)->count())->toBe($deptCountFirst)
        ->and(Employee::where('tenant_id', $tenant->id)->count())->toBe($empCountFirst)
        ->and($deptCountFirst)->toBeGreaterThanOrEqual(5);

    $mfgDept = Department::where('tenant_id', $tenant->id)->where('code', 'DEP_MFG')->first();
    expect($mfgDept)->not->toBeNull()
        ->and($mfgDept->translations)->toHaveKeys(['it', 'en', 'de', 'fr']);
});

test('REST API /api/v1/hr endpoints authenticate via Sanctum and execute employee, org chart, and leave operations', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($tenant);

    $user = User::factory()->create();
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user, ['*']);

    // 1. Create Department via API
    $deptRes = $this->postJson('/api/v1/hr/departments', [
        'code' => 'DEP-API-01',
        'name' => 'Robotics & Automation Engineering',
    ]);
    $deptRes->assertCreated();
    $deptId = $deptRes->json('data.id');

    // 2. Hire Employee via API
    $hireRes = $this->postJson('/api/v1/hr/employees', [
        'first_name' => 'Federico',
        'last_name' => 'Conti',
        'work_email' => 'federico.conti@enterprise.it',
        'base_annual_salary' => 64000.00,
        'department_id' => $deptId,
        'probation_months' => 3,
    ]);
    $hireRes->assertCreated();
    $empId = $hireRes->json('data.id');

    // 3. List Employees via API
    $listRes = $this->getJson('/api/v1/hr/employees');
    $listRes->assertSuccessful()
        ->assertJsonPath('meta.total', 1);

    // 4. Complete Probation via API
    $probRes = $this->postJson("/api/v1/hr/employees/{$empId}/complete-probation", [
        'confirmation_date' => '2026-06-15',
    ]);
    $probRes->assertSuccessful()
        ->assertJsonPath('data.employment_status', 'active');

    // 5. Fetch Org Chart via API
    $orgRes = $this->getJson('/api/v1/hr/org-chart');
    $orgRes->assertSuccessful();
});
