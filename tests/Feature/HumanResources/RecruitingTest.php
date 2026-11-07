<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Jobs\PurgeExpiredApplicationsJob;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\JobApplication;
use Modules\HumanResources\Models\OnboardingChecklist;

uses(RefreshDatabase::class);

function recruitMember(Tenant $tenant, string $role = 'admin'): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@example.ch', 'password' => bcrypt('secret123'), 'locale' => 'it']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function recruitApi(?User $user = null, ?Tenant $tenant = null): mixed
{
    Sanctum::actingAs($user ?? test()->manager);

    return test()->withHeader('X-Tenant-Id', ($tenant ?? test()->tenant)->id);
}

function recruitPosting(string $status = 'open'): string
{
    return recruitApi()->postJson('/api/v1/hr/job-postings', ['title' => 'Muratore', 'status' => $status, 'department_id' => test()->department->id])->assertCreated()->json('data.id');
}

/**
 * @param  array<string, mixed>  $values
 */
function recruitApplication(string $posting, array $values = []): string
{
    return recruitApi()->postJson('/api/v1/hr/job-applications', ['job_posting_id' => $posting, 'first_name' => 'Giulia', 'last_name' => 'Bianchi', 'email' => 'giulia@example.ch', ...$values])->assertCreated()->json('data.id');
}

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    $this->tenant = Tenant::create(['name' => 'Recluta SA', 'legal_name' => 'Recluta SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->other = Tenant::create(['name' => 'Altra SA', 'legal_name' => 'Altra SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->manager = recruitMember($this->tenant);
    $this->department = Department::factory()->create(['tenant_id' => $this->tenant->id]);
});

test('an application runs through the pipeline with its history', function (): void {
    $id = recruitApplication(recruitPosting());
    $stages = collect(recruitApi()->getJson('/api/v1/hr/pipeline-stages')->assertOk()->json('data'))->keyBy('name');

    recruitApi()->postJson("/api/v1/hr/job-applications/{$id}/move", ['pipeline_stage_id' => $stages['Colloquio']['id']])->assertOk();
    recruitApi()->postJson("/api/v1/hr/job-applications/{$id}/notes", ['rating' => 4, 'note' => 'Esperienza solida nei cantieri'])->assertCreated();

    $application = recruitApi()->getJson("/api/v1/hr/job-applications/{$id}")->assertOk()->json('data');

    expect($application['stage']['name'])->toBe('Colloquio')
        ->and($application['stage_events'])->toHaveCount(2)
        ->and($application['interview_notes'][0]['note'])->toBe('Esperienza solida nei cantieri')
        ->and($application['first_name'])->toBe('Giulia');
});

test('a draft posting takes no application and a closing stage needs the closing action', function (): void {
    recruitApi()->postJson('/api/v1/hr/job-applications', ['job_posting_id' => recruitPosting('draft'), 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.ch'])->assertUnprocessable();

    $id = recruitApplication(recruitPosting());
    $stages = collect(recruitApi()->getJson('/api/v1/hr/pipeline-stages')->json('data'))->keyBy('name');
    recruitApi()->postJson("/api/v1/hr/job-applications/{$id}/move", ['pipeline_stage_id' => $stages['Assunta']['id']])->assertUnprocessable();
});

test('personal data are encrypted at rest and stay out of the audit ledger', function (): void {
    $id = recruitApplication(recruitPosting(), ['cover_note' => 'Lettera riservata']);
    recruitApi()->postJson("/api/v1/hr/job-applications/{$id}/notes", ['note' => 'Giudizio riservato'])->assertCreated();

    $row = DB::table('hr_job_applications')->where('id', $id)->first();
    $note = DB::table('hr_interview_notes')->first();
    $ledger = AuditLedgerEntry::query()->withoutGlobalScopes()->get()->toJson();

    expect($row->email)->not->toContain('giulia')->and($row->first_name)->not->toBe('Giulia')->and($note->note)->not->toContain('riservato')
        ->and($ledger)->not->toContain('JobApplication')->not->toContain('InterviewNote')->not->toContain('giulia@example.ch');
});

test('a rejection sets the retention, which ends in automatic erasure with history and notes', function (): void {
    recruitApi()->putJson('/api/v1/hr/recruiting-settings', ['retention_days' => 30])->assertOk();
    $id = recruitApplication(recruitPosting());
    recruitApi()->postJson("/api/v1/hr/job-applications/{$id}/notes", ['note' => 'Nota'])->assertCreated();

    expect(recruitApi()->postJson("/api/v1/hr/job-applications/{$id}/reject")->assertOk()->json('data.retention_until'))->toBe('2026-12-06');

    (new PurgeExpiredApplicationsJob($this->tenant->id))->handle();
    expect(DB::table('hr_job_applications')->count())->toBe(1);

    $this->travelTo('2026-12-06 10:00:00');
    (new PurgeExpiredApplicationsJob($this->tenant->id))->handle();
    expect(DB::table('hr_job_applications')->count())->toBe(1);

    $this->travelTo('2026-12-07 10:00:00');
    (new PurgeExpiredApplicationsJob($this->tenant->id))->handle();

    expect(DB::table('hr_job_applications')->count())->toBe(0)
        ->and(DB::table('hr_interview_notes')->count())->toBe(0)
        ->and(DB::table('hr_application_stage_events')->count())->toBe(0);
});

test('the purge leaves running applications, other companies and consented ones alone', function (): void {
    $posting = recruitPosting();
    $running = recruitApplication($posting);
    $consented = recruitApplication($posting, ['consent_until' => '2027-06-30']);
    $rejected = recruitApplication($posting);
    recruitApi()->postJson("/api/v1/hr/job-applications/{$consented}/reject")->assertOk()->assertJsonPath('data.retention_until', '2027-06-30');
    recruitApi()->postJson("/api/v1/hr/job-applications/{$rejected}/reject")->assertOk();

    $this->travelTo('2027-06-01 10:00:00');
    (new PurgeExpiredApplicationsJob($this->other->id))->handle();
    expect(DB::table('hr_job_applications')->count())->toBe(3);

    (new PurgeExpiredApplicationsJob($this->tenant->id))->handle();
    expect(DB::table('hr_job_applications')->pluck('id')->sort()->values()->all())->toBe(collect([$running, $consented])->sort()->values()->all());
});

test('closing a posting starts the retention of what is still open, and a candidate can ask for erasure at once', function (): void {
    $posting = recruitPosting();
    $open = recruitApplication($posting);
    $other = recruitApplication($posting);

    recruitApi()->postJson("/api/v1/hr/job-postings/{$posting}/close")->assertOk();
    expect(JobApplication::query()->withoutGlobalScopes()->findOrFail($open)->retention_until?->format('Y-m-d'))->toBe('2027-05-05');

    recruitApi()->deleteJson("/api/v1/hr/job-applications/{$other}")->assertNoContent();
    expect(JobApplication::query()->withoutGlobalScopes()->find($other))->toBeNull();
});

test('a hired candidate becomes an employee with a dated onboarding checklist', function (): void {
    $template = recruitApi()->postJson('/api/v1/hr/onboarding-templates', ['name' => 'Cantiere', 'tasks' => [
        ['title' => 'Preparare contratto', 'due_after_days' => -5],
        ['title' => 'Consegnare DPI', 'due_after_days' => 0],
    ]])->assertCreated()->json('data.id');
    $id = recruitApplication(recruitPosting());

    $hired = recruitApi()->postJson("/api/v1/hr/job-applications/{$id}/hire", ['base_annual_salary' => 72000, 'hire_date' => '2026-12-01', 'onboarding_template_id' => $template])->assertCreated()->json('data');

    $employee = Employee::query()->withoutGlobalScopes()->findOrFail($hired['employee_id']);
    expect($employee->full_name)->toBe('Giulia Bianchi')
        ->and($employee->department_id)->toBe($this->department->id)
        ->and($employee->currency)->toBe('CHF');

    $application = JobApplication::query()->withoutGlobalScopes()->findOrFail($id);
    expect($application->status)->toBe('hired')->and($application->employee_id)->toBe($employee->id)->and($application->retention_until)->not->toBeNull();

    $checklist = recruitApi()->getJson("/api/v1/hr/onboarding-checklists?employee_id={$employee->id}")->assertOk()->json('data.0');
    expect(array_column($checklist['tasks'], 'due_on'))->toBe(['2026-11-26', '2026-12-01']);

    recruitApi()->postJson("/api/v1/hr/job-applications/{$id}/hire", ['base_annual_salary' => 72000, 'hire_date' => '2026-12-01'])->assertUnprocessable();

    foreach ($checklist['tasks'] as $task) {
        recruitApi()->postJson("/api/v1/hr/onboarding-tasks/{$task['id']}/complete")->assertOk();
    }

    expect(OnboardingChecklist::query()->withoutGlobalScopes()->sole()->completed_at)->not->toBeNull();

    $this->travelTo('2027-06-01 10:00:00');
    (new PurgeExpiredApplicationsJob($this->tenant->id))->handle();
    expect(DB::table('hr_job_applications')->count())->toBe(0)->and(Employee::query()->withoutGlobalScopes()->find($employee->id))->not->toBeNull();
});

test('tenants are isolated and members reach nothing', function (): void {
    $id = recruitApplication(recruitPosting());
    $otherManager = recruitMember($this->other);
    $foreignDepartment = Department::factory()->create(['tenant_id' => $this->other->id]);

    recruitApi($otherManager, $this->other)->getJson("/api/v1/hr/job-applications/{$id}")->assertNotFound();
    recruitApi($otherManager, $this->other)->deleteJson("/api/v1/hr/job-applications/{$id}")->assertNotFound();
    expect(recruitApi($otherManager, $this->other)->getJson('/api/v1/hr/job-applications')->assertOk()->json('data'))->toBe([]);
    recruitApi()->postJson('/api/v1/hr/job-postings', ['title' => 'X', 'department_id' => $foreignDepartment->id])->assertUnprocessable();
    recruitApi($otherManager, $this->other)->postJson('/api/v1/hr/job-applications', ['job_posting_id' => JobApplication::query()->withoutGlobalScopes()->findOrFail($id)->job_posting_id, 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.ch'])->assertUnprocessable();

    $member = recruitMember($this->tenant, 'member');
    recruitApi($member)->getJson('/api/v1/hr/job-applications')->assertForbidden();
    recruitApi($member)->getJson("/api/v1/hr/job-applications/{$id}")->assertForbidden();
});
