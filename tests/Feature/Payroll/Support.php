<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\PayrollRun;

function payrollTenant(string $country = 'CH'): Tenant
{
    return Tenant::create([
        'name' => 'Paghe '.Str::random(6),
        'legal_name' => 'Paghe SA',
        'country' => $country,
        'default_currency' => $country === 'CH' ? 'CHF' : 'EUR',
    ]);
}

/**
 * @param  list<string>|null  $permissions
 */
function payrollMember(Tenant $tenant, string $role = 'member', ?array $permissions = null): User
{
    $user = User::create([
        'name' => ucfirst($role).' '.Str::random(4),
        'email' => Str::random(12).'@example.ch',
        'password' => bcrypt('secret123'),
        'locale' => 'it',
    ]);
    $user->tenants()->attach($tenant->id, [
        'id' => (string) Str::uuid(),
        'role' => $role,
        'permissions' => $permissions === null ? null : json_encode($permissions),
    ]);

    return $user;
}

/**
 * Requests as a user, in a tenant.
 */
function payrollApi(User $user, Tenant $tenant): mixed
{
    Sanctum::actingAs($user);

    return test()->withHeader('X-Tenant-Id', $tenant->id);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function payrollEmployee(Tenant $tenant, array $attributes = [], ?User $user = null): Employee
{
    return Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $user?->id,
        'first_name' => 'Mario',
        'last_name' => 'Rossi',
        'full_name' => 'Mario Rossi',
        'gender' => 'M',
        'birth_date' => '1986-05-10',
        'hire_date' => '2020-01-01',
        'termination_date' => null,
        'base_annual_salary' => '84500.00',
        'fte_ratio' => '1.00',
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function payrollProfile(Tenant $tenant, Employee $employee, array $attributes = []): PayrollEmployeeProfile
{
    return PayrollEmployeeProfile::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, ...$attributes]);
}

/**
 * A Swiss company ready to calculate, with the settings of the desktop test context.
 */
function payrollSwissSetup(Tenant $tenant, User $manager): void
{
    payrollApi($manager, $tenant)->postJson('/api/v1/payroll/setup')->assertOk();
    payrollApi($manager, $tenant)->putJson('/api/v1/payroll/settings/swiss', [
        'nbu_employee_rate_percent' => '1.2',
        'bu_employer_rate_percent' => '0.1',
        'ktg_employee_rate_percent' => '0.5',
        'ktg_employer_rate_percent' => '0.5',
        'fak_employer_rate_percent' => '1.5',
    ])->assertOk();
}

function payrollCalculate(Tenant $tenant, User $manager, int $year, int $month): TestResponse
{
    return payrollApi($manager, $tenant)->postJson('/api/v1/payroll/runs/calculate', ['year' => $year, 'month' => $month]);
}

function payrollClosedRun(Tenant $tenant, User $manager, int $year, int $month): PayrollRun
{
    $id = payrollCalculate($tenant, $manager, $year, $month)->assertCreated()->json('data.id');
    payrollApi($manager, $tenant)->postJson("/api/v1/payroll/runs/{$id}/finalize")->assertOk();

    return PayrollRun::query()->withoutGlobalScopes()->findOrFail($id);
}
