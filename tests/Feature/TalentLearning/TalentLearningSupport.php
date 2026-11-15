<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Employee;

/*
| Helpers shared by the talent and learning feature tests.
*/

function tlTenant(string $name = 'Talent'): Tenant
{
    return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
}

function tlMember(Tenant $tenant, string $role, array $permissions = []): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@talent-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode($permissions)]);

    return $user;
}

function tlAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

function tlDepartment(Tenant $tenant, string $name = 'Engineering'): Department
{
    return Department::factory()->create(['tenant_id' => $tenant->id, 'name' => $name]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function tlEmployee(Tenant $tenant, array $overrides = []): Employee
{
    return Employee::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'EUR', 'base_annual_salary' => '40000.00', ...$overrides]);
}

/**
 * A member user linked to an employee, as a learner or a survey respondent.
 *
 * @param  array<string, mixed>  $employeeOverrides
 * @return array{user: User, employee: Employee}
 */
function tlLearner(Tenant $tenant, array $employeeOverrides = []): array
{
    $user = tlMember($tenant, 'member');
    $employee = tlEmployee($tenant, ['user_id' => $user->id, ...$employeeOverrides]);

    return ['user' => $user, 'employee' => $employee];
}
