<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\ShiftPlanning\Domain\Enums\Jurisdiction;
use Modules\ShiftPlanning\Domain\Enums\PeriodType;
use Modules\ShiftPlanning\Domain\Enums\RosterStatus;
use Modules\ShiftPlanning\Domain\Services\PublicHolidayCalendar;
use Modules\ShiftPlanning\Infrastructure\Models\RosterSchedulePeriod;
use Modules\ShiftPlanning\Infrastructure\Models\ShiftTemplate;

/**
 * Builders of the shift planning tests. The HR rows (employees, contracts, leave, certificates, skills) are written
 * directly: the planner reads them through its ports. Foreign keys are deferred, the transaction of the test is rolled back.
 */
function spDeferForeignKeys(): void
{
    DB::statement('PRAGMA defer_foreign_keys = ON');
}

function spTenant(string $country = 'CH'): Tenant
{
    return Tenant::create([
        'name' => 'Roster '.Str::random(5),
        'legal_name' => 'Roster SA',
        'vat_number' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
        'fiscal_code' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
        'country' => $country,
        'default_currency' => $country === 'CH' ? 'CHF' : 'EUR',
    ]);
}

function spUser(Tenant $tenant, string $role = 'member'): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);

    return $user;
}

function spActAs(Tenant $tenant, User $user): User
{
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', (string) $tenant->id);

    return $user;
}

/**
 * An employee with an active contract. Returns the employee id.
 */
function spEmployee(Tenant $tenant, string $name = 'Anna Rossi', float $hourlyCost = 20.0, float $weeklyHours = 40.0, ?User $user = null, bool $withContract = true): string
{
    $id = (string) Str::uuid();
    [$first, $last] = array_pad(explode(' ', $name, 2), 2, 'Test');
    DB::table('hr_employees')->insert([
        'id' => $id, 'tenant_id' => $tenant->id, 'user_id' => $user?->id, 'employee_number' => 'E'.Str::random(6), 'first_name' => $first, 'last_name' => $last,
        'full_name' => $name, 'work_email' => Str::uuid().'@example.com', 'hire_date' => '2024-01-01', 'created_at' => now(), 'updated_at' => now(),
    ]);

    if ($withContract) {
        DB::table('hr_employment_contracts')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'employee_id' => $id, 'contract_number' => 'C'.Str::random(6), 'job_title' => 'Operator',
            'start_date' => '2024-01-01', 'weekly_hours' => $weeklyHours, 'hourly_rate' => $hourlyCost, 'loaded_hourly_cost' => $hourlyCost,
            'status' => 'active', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $id;
}

function spSkill(Tenant $tenant, string $code = 'FORK'): string
{
    $id = (string) Str::uuid();
    DB::table('hr_skills')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'code' => $code, 'name' => $code, 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function spGiveSkill(Tenant $tenant, string $employeeId, string $skillId): void
{
    DB::table('hr_employee_skills')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'employee_id' => $employeeId, 'skill_id' => $skillId, 'created_at' => now(), 'updated_at' => now()]);
}

function spCertification(Tenant $tenant, string $employeeId, string $expiry, bool $mandatory = true): void
{
    DB::table('hr_certifications')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'employee_id' => $employeeId, 'code' => 'HSE-1', 'name' => 'Safety training',
        'expiry_date' => $expiry, 'is_mandatory' => $mandatory, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function spLeave(Tenant $tenant, string $employeeId, string $from, string $to, string $status = 'approved'): void
{
    DB::table('hr_leave_requests')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'employee_id' => $employeeId, 'leave_type_id' => (string) Str::uuid(), 'request_number' => 'L'.Str::random(6),
        'start_date' => $from, 'end_date' => $to, 'total_hours' => 8, 'total_days' => 1, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function spTemplate(Tenant $tenant, string $code, string $start, string $end, int $break = 0, bool $onCall = false): ShiftTemplate
{
    return ShiftTemplate::create([
        'tenant_id' => $tenant->id, 'code' => $code, 'name' => ['it' => $code, 'de' => $code, 'fr' => $code, 'en' => $code],
        'start_time' => $start.':00', 'end_time' => $end.':00', 'break_minutes' => $break, 'is_on_call' => $onCall,
    ]);
}

/**
 * A Monday at least two weeks in the future whose week (and the Monday after it) has no public holiday in Switzerland or
 * Italy: shifts of a roster must start after now to be claimed or swapped, and a holiday would add a surcharge to the costs
 * the tests assert.
 */
function spMonday(): CarbonImmutable
{
    $calendar = new PublicHolidayCalendar;
    $monday = CarbonImmutable::today()->next(CarbonInterface::MONDAY)->addWeeks(2);

    while (true) {
        $clear = true;

        for ($offset = 0; $offset <= 7; $offset++) {
            $day = $monday->addDays($offset);
            $clear = $clear && ! $calendar->isHoliday(Jurisdiction::Switzerland, $day) && ! $calendar->isHoliday(Jurisdiction::Italy, $day);
        }

        if ($clear) {
            return $monday;
        }

        $monday = $monday->addWeek();
    }
}

function spPeriod(Tenant $tenant, ?CarbonImmutable $start = null, string $country = 'CH', RosterStatus $status = RosterStatus::Draft): RosterSchedulePeriod
{
    $start ??= spMonday();

    return RosterSchedulePeriod::create([
        'tenant_id' => $tenant->id, 'period_type' => PeriodType::Weekly, 'jurisdiction' => Jurisdiction::from($country),
        'start_date' => $start->format('Y-m-d'), 'end_date' => $start->addDays(6)->format('Y-m-d'), 'status' => $status,
    ]);
}

/**
 * Plans a shift through the API and returns the response.
 */
function spAssign(mixed $test, RosterSchedulePeriod $period, ShiftTemplate $template, string $date, ?string $employeeId, ?string $skillId = null): TestResponse
{
    return $test->postJson('/api/v1/shift-planning/assignments', array_filter([
        'roster_schedule_period_id' => $period->id, 'shift_date' => $date, 'shift_template_id' => $template->id, 'employee_id' => $employeeId, 'required_role_or_skill_id' => $skillId,
    ], static fn ($value): bool => $value !== null));
}

/**
 * @return list<string> the rule codes of the findings of a response (problem details or success body)
 */
function spRuleCodes(TestResponse $response): array
{
    $issues = $response->json('invalid_params') ?? $response->json('data.warnings') ?? [];

    return array_values(array_map(static fn (array $issue): string => $issue['rule_code'], $issues));
}
