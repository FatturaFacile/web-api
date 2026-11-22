<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Builders of the resource planning tests. The rows of the neighbouring modules (employees, contracts, leave, projects,
 * opportunities, certificates) are written directly: the planner reads them through its ports. Foreign keys are deferred, the
 * transaction of the test is rolled back.
 *
 * Calendar anchors (2027): the week of Monday 2027-02-08 has no holiday in Switzerland or Italy; the week of Monday 2027-05-03
 * has Ascension on Thursday 2027-05-06 (a holiday in every canton); Tuesday 2027-06-29 is the Ticino holiday of Saints Peter and
 * Paul; Tuesday 2027-12-07 is the patron saint of Milan and Wednesday 2027-12-08 the Immaculate Conception.
 */
const RP_CLEAN_WEEK = '2027-02-08';

const RP_ASCENSION_WEEK = '2027-05-03';

function rpDeferForeignKeys(): void
{
    DB::statement('PRAGMA defer_foreign_keys = ON');
}

function rpTenant(string $country = 'CH'): Tenant
{
    return Tenant::create([
        'name' => 'Planning '.Str::random(5),
        'legal_name' => 'Planning SA',
        'vat_number' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
        'fiscal_code' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
        'country' => $country,
        'default_currency' => $country === 'CH' ? 'CHF' : 'EUR',
    ]);
}

function rpUser(Tenant $tenant, string $role = 'owner'): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);

    return $user;
}

function rpActAs(Tenant $tenant, User $user): User
{
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', (string) $tenant->id);

    return $user;
}

/**
 * An employee with an active contract. Returns the employee id.
 *
 * @param  array<string, mixed>|null  $metadata
 */
function rpEmployee(Tenant $tenant, string $name = 'Anna Rossi', float $weeklyHours = 40.0, float $ftePercentage = 100.0, ?array $metadata = null, ?string $departmentId = null, float $hourlyCost = 50.0): string
{
    $id = (string) Str::uuid();
    [$first, $last] = array_pad(explode(' ', $name, 2), 2, 'Test');

    DB::table('hr_employees')->insert([
        'id' => $id, 'tenant_id' => $tenant->id, 'employee_number' => 'E'.Str::random(6), 'first_name' => $first, 'last_name' => $last,
        'full_name' => $name, 'work_email' => Str::uuid().'@example.com', 'hire_date' => '2024-01-01', 'department_id' => $departmentId,
        'employment_status' => 'active', 'metadata' => $metadata === null ? null : json_encode($metadata), 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('hr_employment_contracts')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'employee_id' => $id, 'contract_number' => 'C'.Str::random(6), 'job_title' => 'Engineer',
        'start_date' => '2024-01-01', 'weekly_hours' => $weeklyHours, 'fte_percentage' => $ftePercentage, 'hourly_rate' => $hourlyCost,
        'loaded_hourly_cost' => $hourlyCost, 'status' => 'active', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function rpDepartment(Tenant $tenant, string $name = 'Engineering'): string
{
    $id = (string) Str::uuid();
    DB::table('hr_departments')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'code' => 'D'.Str::random(5), 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function rpProject(Tenant $tenant, string $status = 'active', string $name = 'Plant retrofit'): string
{
    $id = (string) Str::uuid();
    DB::table('projects')->insert([
        'id' => $id, 'tenant_id' => $tenant->id, 'contact_id' => (string) Str::uuid(), 'project_number' => 'P'.Str::random(6), 'name' => $name, 'status' => $status,
        'planned_start_date' => '2027-01-01', 'planned_end_date' => '2027-12-31', 'project_manager_user_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function rpWbsNode(Tenant $tenant, string $projectId, string $title = 'Commissioning'): string
{
    $id = (string) Str::uuid();
    DB::table('project_tasks')->insert([
        'id' => $id, 'tenant_id' => $tenant->id, 'project_id' => $projectId, 'task_number' => 'T'.Str::random(5), 'title' => $title,
        'planned_start_date' => '2027-01-01', 'planned_end_date' => '2027-12-31', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function rpOpportunity(Tenant $tenant, string $status = 'open', string $name = 'Warehouse automation'): string
{
    $id = (string) Str::uuid();
    DB::table('crm_opportunities')->insert([
        'id' => $id, 'tenant_id' => $tenant->id, 'account_id' => (string) Str::uuid(), 'stage_id' => (string) Str::uuid(), 'opportunity_number' => 'O'.Str::random(6),
        'name' => $name, 'expected_close_date' => '2027-03-31', 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function rpLeave(Tenant $tenant, string $employeeId, string $from, string $to, string $status = 'approved', float $totalDays = 1.0, float $totalHours = 8.0): void
{
    DB::table('hr_leave_requests')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'employee_id' => $employeeId, 'leave_type_id' => (string) Str::uuid(), 'request_number' => 'L'.Str::random(6),
        'start_date' => $from, 'end_date' => $to, 'total_hours' => $totalHours, 'total_days' => $totalDays, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function rpSkill(Tenant $tenant, string $name = 'PLC programming', string $category = 'technical', bool $requiresCertification = false, ?string $certificationCode = null): string
{
    $id = (string) Str::uuid();
    DB::table('rp_skills')->insert([
        'id' => $id, 'tenant_id' => $tenant->id, 'category' => $category, 'skill_name' => $name, 'requires_certification' => $requiresCertification,
        'certification_code' => $certificationCode, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function rpGiveSkill(Tenant $tenant, string $employeeId, string $skillId, int $level = 3, ?string $validUntil = null): void
{
    DB::table('rp_employee_skill_profiles')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'employee_id' => $employeeId, 'skill_id' => $skillId, 'proficiency_level' => $level,
        'valid_until_date' => $validUntil, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * A safety certificate of the HSE module (Mod 099).
 */
function rpHseCertificate(Tenant $tenant, string $employeeId, string $type, ?string $expiresAt, ?string $revokedAt = null): void
{
    DB::table('hse_safety_certificates')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'employee_id' => $employeeId, 'certificate_type' => $type, 'issued_at' => '2025-01-01',
        'expires_at' => $expiresAt, 'status' => $revokedAt === null ? 'valid' : 'revoked', 'revoked_at' => $revokedAt, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * Books an employee through the API and returns the response.
 *
 * @param  array<string, mixed>  $overrides
 */
function rpAllocate(mixed $test, string $employeeId, string $start, string $end, float $hours, array $overrides = []): TestResponse
{
    return $test->postJson('/api/v1/resource-planning/allocations', array_merge([
        'employee_id' => $employeeId,
        'booking_type' => 'SoftTentative',
        'start_date' => $start,
        'end_date' => $end,
        'allocated_hours_per_day' => $hours,
    ], $overrides));
}
