<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/ResourcePlanningTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    rpDeferForeignKeys();
});

/**
 * A forklift driving skill that needs the safety certificate of Mod 099, and three employees: Anna (level 4, valid
 * certificate), Bruno (level 5, certificate expired), Carla (level 2, valid certificate).
 *
 * @return array<string, string>
 */
function rpCrew(object $test): array
{
    $tenant = rpTenant('CH');
    rpActAs($tenant, rpUser($tenant));
    $skill = rpSkill($tenant, 'Forklift driving', 'safety', true, 'forklift_patentino_muletto');
    $anna = rpEmployee($tenant, 'Anna Rossi');
    $bruno = rpEmployee($tenant, 'Bruno Bianchi');
    $carla = rpEmployee($tenant, 'Carla Verdi');

    rpGiveSkill($tenant, $anna, $skill, 4);
    rpGiveSkill($tenant, $bruno, $skill, 5);
    rpGiveSkill($tenant, $carla, $skill, 2);
    rpHseCertificate($tenant, $anna, 'forklift_patentino_muletto', '2099-01-01');
    rpHseCertificate($tenant, $bruno, 'forklift_patentino_muletto', '2020-01-01');
    rpHseCertificate($tenant, $carla, 'forklift_patentino_muletto', null);

    return ['tenant' => $tenant->id, 'skill' => $skill, 'anna' => $anna, 'bruno' => $bruno, 'carla' => $carla];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function rpSearch(object $test, string $skill, int $minLevel, array $overrides = []): TestResponse
{
    return $test->postJson('/api/v1/resource-planning/candidates/search', array_merge([
        'start_date' => RP_CLEAN_WEEK,
        'end_date' => '2027-02-12',
        'allocated_hours_per_day' => 4,
        'requirements' => [['skill_id' => $skill, 'min_level' => $minLevel]],
    ], $overrides));
}

it('suggests only the employees with the proficiency and a valid safety certificate', function (): void {
    $crew = rpCrew($this);

    $candidates = rpSearch($this, $crew['skill'], 3)->assertOk()->json('data');

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]['employee_name'])->toBe('Anna Rossi')
        ->and($candidates[0]['eligible'])->toBeTrue()
        ->and($candidates[0]['matched_skills'][0]['certification_status'])->toBe('valid')
        ->and($candidates[0]['available_hours'])->toEqual(40.0)
        ->and($candidates[0]['requested_hours'])->toEqual(20.0)
        ->and($candidates[0]['residual_hours_per_day'])->toEqual(8.0);
});

it('explains why the others are not eligible', function (): void {
    $crew = rpCrew($this);

    $candidates = collect(rpSearch($this, $crew['skill'], 3, ['include_ineligible' => true])->assertOk()->json('data'))->keyBy('employee_name');

    expect($candidates->keys()->first())->toBe('Anna Rossi')
        ->and($candidates['Bruno Bianchi']['eligible'])->toBeFalse()
        ->and($candidates['Bruno Bianchi']['blockers'][0]['code'])->toBe('certificate_expired')
        ->and($candidates['Carla Verdi']['blockers'][0]['code'])->toBe('proficiency_too_low')
        ->and($candidates['Carla Verdi']['blockers'][0]['params']['min_level'])->toBe(3);
});

it('ranks by proficiency when the certificates are not required', function (): void {
    $crew = rpCrew($this);

    $candidates = rpSearch($this, $crew['skill'], 3, ['require_valid_certificates' => false])->assertOk()->json('data');

    expect(array_column($candidates, 'employee_name'))->toBe(['Bruno Bianchi', 'Anna Rossi'])
        ->and($candidates[0]['score'])->toBeGreaterThan($candidates[1]['score']);
});

it('measures the residual availability net of what is already booked', function (): void {
    $crew = rpCrew($this);
    $tenant = Tenant::query()->findOrFail($crew['tenant']);
    rpAllocate($this, $crew['anna'], RP_CLEAN_WEEK, '2027-02-12', 6, ['booking_type' => 'HardCommitted', 'project_id' => rpProject($tenant)])->assertCreated();

    $candidates = collect(rpSearch($this, $crew['skill'], 3, ['include_ineligible' => true])->assertOk()->json('data'))->keyBy('employee_name');

    expect($candidates['Anna Rossi']['eligible'])->toBeFalse()
        ->and($candidates['Anna Rossi']['residual_hours_per_day'])->toEqual(2.0)
        ->and($candidates['Anna Rossi']['peak_load_after_percent'])->toEqual(125.0)
        ->and(collect($candidates['Anna Rossi']['blockers'])->pluck('code')->all())->toBe(['insufficient_availability']);

    // Asking for the 2 hours that are left makes Anna eligible again.
    $fits = rpSearch($this, $crew['skill'], 3, ['allocated_hours_per_day' => 2])->assertOk()->json('data');
    expect($fits)->toHaveCount(1)->and($fits[0]['employee_name'])->toBe('Anna Rossi')->and($fits[0]['peak_load_after_percent'])->toEqual(100.0);
});

it('does not count the holidays and the leave of the window as availability', function (): void {
    $crew = rpCrew($this);
    $tenant = Tenant::query()->findOrFail($crew['tenant']);
    rpLeave($tenant, $crew['anna'], '2027-05-03', '2027-05-04');

    // Ascension week: Monday and Tuesday on leave, Thursday a holiday: Wednesday and Friday are left.
    $candidates = rpSearch($this, $crew['skill'], 3, ['start_date' => RP_ASCENSION_WEEK, 'end_date' => '2027-05-07'])->assertOk()->json('data');

    expect($candidates[0]['employee_name'])->toBe('Anna Rossi')
        ->and($candidates[0]['available_hours'])->toEqual(16.0)
        ->and($candidates[0]['requested_hours'])->toEqual(8.0);
});

it('refuses a skill whose validity ends before the window and a revoked certificate', function (): void {
    $crew = rpCrew($this);
    DB::table('rp_employee_skill_profiles')->where('employee_id', $crew['anna'])->update(['valid_until_date' => '2027-02-10']);
    DB::table('hse_safety_certificates')->where('employee_id', $crew['carla'])->update(['status' => 'revoked', 'revoked_at' => '2026-01-01 00:00:00']);

    $candidates = collect(rpSearch($this, $crew['skill'], 1, ['include_ineligible' => true])->assertOk()->json('data'))->keyBy('employee_name');

    expect($candidates['Anna Rossi']['blockers'][0]['code'])->toBe('skill_expired')
        ->and($candidates['Carla Verdi']['blockers'][0]['code'])->toBe('certificate_missing');
});

it('accepts a certification of the HR training register as a fallback', function (): void {
    $crew = rpCrew($this);
    $tenant = Tenant::query()->findOrFail($crew['tenant']);
    DB::table('hse_safety_certificates')->where('employee_id', $crew['bruno'])->delete();
    DB::table('hr_certifications')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'employee_id' => $crew['bruno'], 'code' => 'forklift_patentino_muletto', 'name' => 'Forklift licence',
        'expiry_date' => '2099-01-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $names = array_column(rpSearch($this, $crew['skill'], 3)->assertOk()->json('data'), 'employee_name');

    expect($names)->toBe(['Bruno Bianchi', 'Anna Rossi']);
});

it('restricts the search to a department and limits the answer', function (): void {
    $crew = rpCrew($this);
    $tenant = Tenant::query()->findOrFail($crew['tenant']);
    $department = rpDepartment($tenant, 'Logistics');
    DB::table('hr_employees')->where('id', $crew['anna'])->update(['department_id' => $department]);

    expect(rpSearch($this, $crew['skill'], 1, ['department_id' => $department, 'require_valid_certificates' => false])->json('data'))->toHaveCount(1);
    expect(rpSearch($this, $crew['skill'], 1, ['require_valid_certificates' => false, 'limit' => 2])->json('data'))->toHaveCount(2);
});

it('warns when a booking needs a skill or certificate the employee does not hold', function (): void {
    $crew = rpCrew($this);
    $tenant = Tenant::query()->findOrFail($crew['tenant']);

    $response = rpAllocate($this, $crew['bruno'], RP_CLEAN_WEEK, '2027-02-12', 4, ['crm_opportunity_id' => rpOpportunity($tenant), 'required_skill_ids' => [$crew['skill']]])->assertCreated();
    expect(array_column($response->json('data.warnings'), 'code'))->toBe(['certificate_expired']);

    $clean = rpAllocate($this, $crew['anna'], RP_CLEAN_WEEK, '2027-02-12', 4, ['crm_opportunity_id' => rpOpportunity($tenant), 'required_skill_ids' => [$crew['skill']]])->assertCreated();
    expect($clean->json('data.warnings'))->toBe([]);
});

it('requires the skills of the search to belong to the tenant', function (): void {
    $crew = rpCrew($this);
    $foreign = rpSkill(rpTenant('IT'), 'Foreign');

    rpSearch($this, $foreign, 3)->assertUnprocessable();
    rpSearch($this, $crew['skill'], 9)->assertUnprocessable();
});
