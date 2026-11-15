<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\ExitInterview;
use Modules\HumanResources\Models\OnboardingChecklist;
use Modules\HumanResources\Models\OnboardingTask;
use Modules\HumanResources\Models\OnboardingTemplate;

uses(RefreshDatabase::class);

function offbMember(Tenant $tenant, string $role = 'admin'): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@example.ch', 'password' => bcrypt(Str::random(24)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function offbApi(?User $user = null, ?Tenant $tenant = null): mixed
{
    Sanctum::actingAs($user ?? test()->manager);

    return test()->withHeader('X-Tenant-Id', ($tenant ?? test()->tenant)->id);
}

/**
 * @param  array<string, mixed>  $payload
 */
function offbTerminate(Employee $employee, array $payload = []): mixed
{
    return offbApi()->postJson("/api/v1/hr/employees/{$employee->id}/terminate", ['termination_date' => '2026-12-15', 'reason' => 'Resignation', ...$payload]);
}

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    $this->tenant = Tenant::create(['name' => 'Uscite SA', 'legal_name' => 'Uscite SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->other = Tenant::create(['name' => 'Altra SA', 'legal_name' => 'Altra SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->manager = offbMember($this->tenant);
    $this->employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'equipment_assigned' => ['Laptop X1', ['name' => 'Company phone', 'asset_tag' => 'PH-7'], ['asset_tag' => 'KEY-3'], ''],
    ]);
});

test('terminating an employee opens the offboarding checklist with the asset returns and the standard tasks', function (): void {
    offbTerminate($this->employee)->assertOk()->assertJsonPath('data.employment_status', 'terminated');

    $checklist = OnboardingChecklist::query()->where('employee_id', $this->employee->id)->firstOrFail();
    $tasks = $checklist->tasks;

    expect($checklist->kind)->toBe('offboarding')
        ->and($checklist->reference_date->format('Y-m-d'))->toBe('2026-12-15')
        ->and($tasks->pluck('code')->all())->toBe(['asset_return', 'asset_return', 'asset_return', 'access_revocation', 'exit_interview', 'final_settlement'])
        ->and($tasks->pluck('title')->take(3)->all())->toBe(['Return asset: Laptop X1', 'Return asset: Company phone', 'Return asset: KEY-3'])
        ->and($tasks->firstWhere('code', 'exit_interview')->due_on->format('Y-m-d'))->toBe('2026-12-12')
        ->and($tasks->firstWhere('code', 'final_settlement')->due_on->format('Y-m-d'))->toBe('2026-12-20')
        ->and($checklist->completed_at)->toBeNull();

    $listed = offbApi()->getJson('/api/v1/hr/offboarding-checklists?employee_id='.$this->employee->id)->assertOk();
    expect($listed->json('data'))->toHaveCount(1)->and($listed->json('data.0.tasks'))->toHaveCount(6)->and($listed->json('data.0.employee.employee_number'))->toBe($this->employee->employee_number);
    offbApi()->getJson('/api/v1/hr/offboarding-checklists/'.$checklist->id)->assertOk()->assertJsonPath('data.kind', 'offboarding');
    expect(offbApi()->getJson('/api/v1/hr/onboarding-checklists')->assertOk()->json('data'))->toBe([]);
});

test('an employee without equipment gets no asset return task and start_offboarding false opens nothing', function (): void {
    $bare = Employee::factory()->create(['tenant_id' => $this->tenant->id, 'equipment_assigned' => []]);
    offbTerminate($bare)->assertOk();
    expect(OnboardingTask::query()->where('code', 'asset_return')->count())->toBe(0)
        ->and(OnboardingChecklist::query()->where('employee_id', $bare->id)->firstOrFail()->tasks)->toHaveCount(3);

    offbTerminate($this->employee, ['start_offboarding' => false])->assertOk();
    expect(OnboardingChecklist::query()->where('employee_id', $this->employee->id)->count())->toBe(0);
});

test('an offboarding template drives the tasks of the termination', function (): void {
    $id = offbApi()->postJson('/api/v1/hr/offboarding-templates', [
        'name' => 'Standard exit',
        'tasks' => [
            ['title' => 'Return equipment', 'code' => 'asset_return', 'due_after_days' => -1],
            ['title' => 'Hand over the projects', 'due_after_days' => -5],
            ['title' => 'Exit interview', 'code' => 'exit_interview', 'due_after_days' => 0],
        ],
    ])->assertCreated()->assertJsonPath('data.kind', 'offboarding')->json('data.id');

    offbTerminate($this->employee, ['offboarding_template_id' => $id])->assertOk();

    $checklist = OnboardingChecklist::query()->where('employee_id', $this->employee->id)->firstOrFail();
    expect($checklist->onboarding_template_id)->toBe($id)
        ->and($checklist->tasks->pluck('code')->all())->toBe(['asset_return', 'asset_return', 'asset_return', 'custom', 'exit_interview'])
        ->and($checklist->tasks->firstWhere('code', 'custom')->due_on->format('Y-m-d'))->toBe('2026-12-10');

    $otherEmployee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
    offbTerminate($otherEmployee)->assertOk();
    expect(OnboardingChecklist::query()->where('employee_id', $otherEmployee->id)->firstOrFail()->onboarding_template_id)->toBe($id);

    offbApi()->putJson("/api/v1/hr/offboarding-templates/{$id}", ['name' => 'Standard exit v2'])->assertOk()->assertJsonPath('data.name', 'Standard exit v2');
    expect(offbApi()->getJson('/api/v1/hr/offboarding-templates')->assertOk()->json('data'))->toHaveCount(1);
    offbApi()->deleteJson("/api/v1/hr/offboarding-templates/{$id}")->assertNoContent();
    expect(offbApi()->getJson('/api/v1/hr/offboarding-templates')->json('data'))->toBe([]);
});

test('templates are validated, unique by name and kept apart from onboarding templates', function (): void {
    $onboarding = OnboardingTemplate::factory()->create(['tenant_id' => $this->tenant->id]);
    $payload = ['name' => 'Exit A', 'tasks' => [['title' => 'One']]];

    offbApi()->postJson('/api/v1/hr/offboarding-templates', $payload)->assertCreated();
    offbApi()->postJson('/api/v1/hr/offboarding-templates', $payload)->assertUnprocessable()->assertJsonValidationErrors('name');
    offbApi()->postJson('/api/v1/hr/offboarding-templates', ['name' => '', 'tasks' => []])->assertUnprocessable()->assertJsonValidationErrors(['name', 'tasks']);
    offbApi()->postJson('/api/v1/hr/offboarding-templates', ['name' => 'Exit B', 'tasks' => [['title' => 'x', 'code' => 'bogus', 'due_after_days' => 900]]])->assertUnprocessable()->assertJsonValidationErrors(['tasks.0.code', 'tasks.0.due_after_days']);
    offbApi()->postJson('/api/v1/hr/onboarding-templates', ['name' => 'Exit A', 'tasks' => [['title' => 'x']]])->assertUnprocessable()->assertJsonValidationErrors('name');

    offbApi()->putJson("/api/v1/hr/offboarding-templates/{$onboarding->id}", ['name' => 'Hijack'])->assertNotFound();
    offbApi()->deleteJson("/api/v1/hr/offboarding-templates/{$onboarding->id}")->assertNotFound();
    offbTerminate($this->employee, ['offboarding_template_id' => $onboarding->id])->assertNotFound();
    expect(Employee::query()->findOrFail($this->employee->id)->employment_status)->toBe('active');

    $foreign = OnboardingTemplate::factory()->offboarding()->create(['tenant_id' => $this->other->id]);
    offbTerminate($this->employee, ['offboarding_template_id' => $foreign->id])->assertUnprocessable();
});

test('the tasks are completed one by one and the last one closes the checklist', function (): void {
    offbTerminate($this->employee)->assertOk();
    $checklist = OnboardingChecklist::query()->where('employee_id', $this->employee->id)->firstOrFail();

    foreach ($checklist->tasks as $index => $task) {
        $response = offbApi()->postJson("/api/v1/hr/offboarding-tasks/{$task->id}/complete")->assertOk();
        expect($response->json('data.completed_at'))->not->toBeNull()->and($response->json('data.completed_by'))->toBe($this->manager->id);
        expect(OnboardingChecklist::query()->findOrFail($checklist->id)->completed_at === null)->toBe($index < $checklist->tasks->count() - 1);
    }

    expect(offbApi()->getJson('/api/v1/hr/offboarding-checklists?status=completed')->json('data'))->toHaveCount(1)
        ->and(offbApi()->getJson('/api/v1/hr/offboarding-checklists?status=open')->json('data'))->toBe([]);
});

test('an onboarding task is not completed through the offboarding endpoint', function (): void {
    $hired = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
    $checklist = OnboardingChecklist::factory()->create(['tenant_id' => $this->tenant->id, 'employee_id' => $hired->id]);
    $task = OnboardingTask::create(['tenant_id' => $this->tenant->id, 'onboarding_checklist_id' => $checklist->id, 'title' => 'Welcome', 'sort_order' => 0]);

    offbApi()->postJson("/api/v1/hr/offboarding-tasks/{$task->id}/complete")->assertUnprocessable();
    expect(OnboardingTask::query()->findOrFail($task->id)->completed_at)->toBeNull();
});

test('an offboarding is started by hand only for a terminated employee and only once', function (): void {
    offbApi()->postJson('/api/v1/hr/offboarding-checklists', ['employee_id' => $this->employee->id])->assertUnprocessable();

    Employee::query()->whereKey($this->employee->id)->update(['employment_status' => 'terminated', 'termination_date' => '2026-11-30']);
    $created = offbApi()->postJson('/api/v1/hr/offboarding-checklists', ['employee_id' => $this->employee->id])->assertCreated();
    expect($created->json('data.kind'))->toBe('offboarding')->and($created->json('data.tasks'))->toHaveCount(6);
    offbApi()->postJson('/api/v1/hr/offboarding-checklists', ['employee_id' => $this->employee->id])->assertUnprocessable();
    offbApi()->postJson('/api/v1/hr/offboarding-checklists', [])->assertUnprocessable()->assertJsonValidationErrors('employee_id');
});

test('an exit interview records the reason code, replaces an earlier one and closes its task', function (): void {
    $payload = ['employee_id' => $this->employee->id, 'conducted_on' => '2026-12-12', 'reason_code' => 'resignation_better_offer', 'would_recommend' => true, 'satisfaction_rating' => 4, 'feedback' => 'Wanted more growth.'];

    offbApi()->postJson('/api/v1/hr/exit-interviews', $payload)->assertUnprocessable();
    offbTerminate($this->employee)->assertOk();

    $created = offbApi()->postJson('/api/v1/hr/exit-interviews', $payload)->assertCreated();
    expect($created->json('data.reason_code'))->toBe('resignation_better_offer')->and($created->json('data.feedback'))->toBe('Wanted more growth.');

    $raw = (string) DB::table('hr_exit_interviews')->where('employee_id', $this->employee->id)->value('feedback');
    expect($raw)->not->toContain('Wanted more growth.');

    $task = OnboardingTask::query()->where('code', 'exit_interview')->firstOrFail();
    expect($task->completed_at)->not->toBeNull()->and($task->completed_by)->toBe($this->manager->id);

    offbApi()->postJson('/api/v1/hr/exit-interviews', [...$payload, 'reason_code' => 'other', 'feedback' => null])->assertCreated();
    expect(ExitInterview::query()->count())->toBe(1)->and(ExitInterview::query()->firstOrFail()->reason_code)->toBe('other');

    $listed = offbApi()->getJson('/api/v1/hr/exit-interviews?reason_code=other')->assertOk();
    expect($listed->json('data'))->toHaveCount(1)->and($listed->json('data.0.employee.full_name'))->toBe($this->employee->full_name);
    expect(offbApi()->getJson('/api/v1/hr/exit-interviews?reason_code=retirement')->json('data'))->toBe([]);

    $codes = offbApi()->getJson('/api/v1/hr/exit-reason-codes')->assertOk()->json('data');
    expect(collect($codes)->firstWhere('code', 'resignation_personal')['voluntary'])->toBeTrue()
        ->and(collect($codes)->firstWhere('code', 'dismissal_redundancy')['voluntary'])->toBeFalse();
});

test('exit interview payloads are validated', function (): void {
    offbTerminate($this->employee)->assertOk();

    offbApi()->postJson('/api/v1/hr/exit-interviews', [])->assertUnprocessable()->assertJsonValidationErrors(['employee_id', 'conducted_on', 'reason_code']);
    offbApi()->postJson('/api/v1/hr/exit-interviews', ['employee_id' => $this->employee->id, 'conducted_on' => '12/12/2026', 'reason_code' => 'bogus', 'satisfaction_rating' => 9])
        ->assertUnprocessable()->assertJsonValidationErrors(['conducted_on', 'reason_code', 'satisfaction_rating']);
});

test('offboarding data is isolated between companies and needs the HR module role', function (): void {
    offbTerminate($this->employee)->assertOk();
    $checklist = OnboardingChecklist::query()->where('employee_id', $this->employee->id)->firstOrFail();
    $task = $checklist->tasks->first();
    $otherManager = offbMember($this->other);
    $foreign = Employee::factory()->create(['tenant_id' => $this->other->id]);
    $foreign->update(['employment_status' => 'terminated', 'termination_date' => '2026-11-30']);
    $interview = ExitInterview::factory()->create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id]);

    offbApi($otherManager, $this->other)->getJson("/api/v1/hr/offboarding-checklists/{$checklist->id}")->assertNotFound();
    offbApi($otherManager, $this->other)->postJson("/api/v1/hr/offboarding-tasks/{$task->id}/complete")->assertNotFound();
    expect(offbApi($otherManager, $this->other)->getJson('/api/v1/hr/offboarding-checklists')->assertOk()->json('data'))->toBe([])
        ->and(offbApi($otherManager, $this->other)->getJson('/api/v1/hr/exit-interviews')->assertOk()->json('data'))->toBe([])
        ->and(offbApi($otherManager, $this->other)->getJson('/api/v1/hr/offboarding-templates')->assertOk()->json('data'))->toBe([]);
    offbApi($otherManager, $this->other)->postJson('/api/v1/hr/offboarding-checklists', ['employee_id' => $this->employee->id])->assertUnprocessable();
    offbApi($otherManager, $this->other)->postJson('/api/v1/hr/exit-interviews', ['employee_id' => $this->employee->id, 'conducted_on' => '2026-12-12', 'reason_code' => 'other'])->assertUnprocessable();
    offbApi($otherManager, $this->other)->postJson("/api/v1/hr/employees/{$this->employee->id}/terminate", ['termination_date' => '2026-12-15', 'reason' => 'x'])->assertNotFound();
    expect($interview->refresh()->reason_code)->not->toBeNull();

    $member = offbMember($this->tenant, 'member');
    offbApi($member)->getJson('/api/v1/hr/offboarding-checklists')->assertForbidden();
    offbApi($member)->getJson('/api/v1/hr/exit-interviews')->assertForbidden();
    offbApi($member)->postJson("/api/v1/hr/offboarding-tasks/{$task->id}/complete")->assertForbidden();
    offbApi($member)->postJson('/api/v1/hr/offboarding-templates', ['name' => 'x', 'tasks' => [['title' => 'y']]])->assertForbidden();
});

test('terminating again after a rehire does not fail because of an offboarding left open', function (): void {
    offbTerminate($this->employee)->assertOk();
    offbTerminate($this->employee)->assertUnprocessable();
    Employee::query()->whereKey($this->employee->id)->update(['employment_status' => 'active', 'termination_date' => null]);

    offbTerminate($this->employee, ['termination_date' => '2027-03-31'])->assertOk();
    expect(OnboardingChecklist::query()->where('employee_id', $this->employee->id)->where('kind', 'offboarding')->count())->toBe(1);
});
