<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Models\Employee;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Models\ApprovalDecision;
use Modules\Workflow\Models\WorkflowAuditEntry;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowEscalationRule;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;
use Modules\Workflow\Models\WorkflowNode;
use Modules\Workflow\Models\WorkflowTransition;
use Modules\Workflow\Services\WorkflowEngineService;
use Modules\Workflow\Services\WorkflowTimeoutProcessor;

require_once __DIR__.'/../Compliance/ComplianceHelpers.php';

uses(RefreshDatabase::class);

function wfxUser(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => $role.'-'.Str::random(4), 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function wfxActAs(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

/**
 * A published definition: start, one approval node with the given attributes, an optional node reached by timeout, end.
 *
 * @param  array<string, mixed>  $approval
 * @param  array<string, mixed>|null  $afterTimeout
 */
function wfxDefinition(Tenant $tenant, string $code, array $approval, ?array $afterTimeout = null): WorkflowDefinition
{
    $definition = WorkflowDefinition::create(['tenant_id' => $tenant->id, 'code' => $code, 'name' => $code, 'target_entity_type' => Contact::class, 'version' => 1, 'status' => 'published', 'is_active' => true]);
    $node = fn (string $key, string $type, array $extra = []) => WorkflowNode::create(['tenant_id' => $tenant->id, 'workflow_definition_id' => $definition->id, 'node_key' => $key, 'name' => $key, 'node_type' => $type, ...$extra]);
    $link = fn (WorkflowNode $from, WorkflowNode $to, string $trigger) => WorkflowTransition::create(['tenant_id' => $tenant->id, 'workflow_definition_id' => $definition->id, 'from_node_id' => $from->id, 'to_node_id' => $to->id, 'action_trigger' => $trigger]);

    $start = $node('start', 'start');
    $review = $node('review', 'approval', ['approval_type' => 'single', 'timeout_hours' => 24, ...$approval]);
    $end = $node('end', 'end');
    $link($start, $review, 'start');
    $link($review, $end, 'approved');

    if ($afterTimeout !== null) {
        $second = $node('second', 'approval', ['approval_type' => 'single', 'timeout_hours' => 24, ...$afterTimeout]);
        $link($review, $second, 'timeout');
        $link($second, $end, 'approved');
    }

    return $definition;
}

function wfxStart(Tenant $tenant, string $code, ?User $initiator = null): WorkflowInstance
{
    $record = Contact::create(['tenant_id' => $tenant->id, 'type' => 'customer', 'company_name' => 'Record '.Str::random(5)]);

    return app(WorkflowEngineService::class)->start(new StartWorkflowDTO(workflowCode: $code, approvable: $record, initiatorUserId: $initiator?->id, tenantId: (string) $tenant->id));
}

function wfxPastDue(WorkflowInstanceStep $step, int $hours = 1): void
{
    WorkflowInstanceStep::query()->whereKey($step->id)->update(['due_at' => now()->subHours($hours)]);
}

function wfxDecide(WorkflowInstanceStep $step, string $decision = 'approved', ?string $key = null)
{
    return test()->postJson("/api/v1/workflows/steps/{$step->id}/decide", array_filter(['decision' => $decision, 'idempotency_key' => $key]));
}

beforeEach(function (): void {
    $this->tenant = complianceTenant();
    $this->admin = wfxUser($this->tenant, 'admin');
    $this->bob = wfxUser($this->tenant, 'purchasing');
    $this->carol = wfxUser($this->tenant, 'member');
    $this->requester = wfxUser($this->tenant, 'sales');
    wfxActAs($this->admin, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('a step nobody decides in time expires the instance when the definition has no timeout path', function (): void {
    wfxDefinition($this->tenant, 'solo', ['assignee_type' => 'direct_user', 'assignee_id' => $this->bob->id]);
    $instance = wfxStart($this->tenant, 'solo', $this->requester);
    $step = $instance->steps()->firstOrFail();
    wfxPastDue($step);

    $counts = app(WorkflowTimeoutProcessor::class)->run();

    expect($counts['timeouts'])->toBe(1)
        ->and($instance->refresh()->status)->toBe('expired')
        ->and($instance->completed_at)->not->toBeNull()
        ->and($step->refresh()->status)->toBe('timed_out')
        ->and($step->sla_breached_at)->not->toBeNull()
        ->and(WorkflowAuditEntry::query()->where('workflow_instance_id', $instance->id)->pluck('event_type')->all())->toContain('step_timed_out', 'instance_expired');

    // A second pass finds nothing to do.
    expect(array_sum(app(WorkflowTimeoutProcessor::class)->run()))->toBe(0);
});

test('a timeout transition sends the instance to the next node', function (): void {
    wfxDefinition($this->tenant, 'with-timeout', ['assignee_type' => 'direct_user', 'assignee_id' => $this->bob->id], ['assignee_type' => 'role', 'assignee_id' => 'admin']);
    $instance = wfxStart($this->tenant, 'with-timeout', $this->requester);
    wfxPastDue($instance->steps()->firstOrFail());

    app(WorkflowTimeoutProcessor::class)->run();

    $steps = $instance->refresh()->steps()->orderBy('created_at')->get();

    expect($instance->status)->toBe('pending')
        ->and($steps)->toHaveCount(2)
        ->and($steps[0]->status)->toBe('timed_out')
        ->and($steps[1]->assigned_role)->toBe('admin')
        ->and($steps[1]->status)->toBe('pending');
});

test('parallel approvers: the first approval closes the other steps, which can no longer be decided', function (): void {
    wfxDefinition($this->tenant, 'or', ['assignee_type' => 'user_list', 'assignee_id' => "{$this->bob->id},{$this->carol->id}", 'approval_type' => 'or_parallel']);
    $instance = wfxStart($this->tenant, 'or', $this->requester);
    $steps = $instance->steps()->get()->keyBy('assigned_user_id');

    expect($steps)->toHaveCount(2);

    wfxActAs($this->bob, $this->tenant);
    wfxDecide($steps[$this->bob->id])->assertOk();

    expect($instance->refresh()->status)->toBe('approved')
        ->and($steps[$this->carol->id]->refresh()->status)->toBe('skipped');

    wfxActAs($this->carol, $this->tenant);
    wfxDecide($steps[$this->carol->id])->assertConflict();
    $this->getJson('/api/v1/workflows/my-tasks')->assertOk()->assertJsonPath('data', []);
});

test('parallel approvers that must all approve expire when one of them times out', function (): void {
    wfxDefinition($this->tenant, 'and', ['assignee_type' => 'user_list', 'assignee_id' => "{$this->bob->id},{$this->carol->id}", 'approval_type' => 'and_parallel']);
    $instance = wfxStart($this->tenant, 'and', $this->requester);
    $steps = $instance->steps()->get()->keyBy('assigned_user_id');

    wfxActAs($this->bob, $this->tenant);
    wfxDecide($steps[$this->bob->id])->assertOk();
    expect($instance->refresh()->status)->toBe('pending');

    wfxPastDue($steps[$this->carol->id]);
    app(WorkflowTimeoutProcessor::class)->run();

    expect($instance->refresh()->status)->toBe('expired');
});

test('sequential approvers open one step at a time in the order of the list', function (): void {
    wfxDefinition($this->tenant, 'seq', ['assignee_type' => 'user_list_sequential', 'assignee_id' => "{$this->bob->id},{$this->carol->id}"]);
    $instance = wfxStart($this->tenant, 'seq', $this->requester);

    expect($instance->steps()->get())->toHaveCount(1)
        ->and($instance->steps()->first()->assigned_user_id)->toBe($this->bob->id);

    wfxActAs($this->carol, $this->tenant);
    $this->getJson('/api/v1/workflows/my-tasks')->assertOk()->assertJsonPath('data', []);

    wfxActAs($this->bob, $this->tenant);
    wfxDecide($instance->steps()->firstOrFail())->assertOk();

    $second = $instance->refresh()->steps()->where('status', 'pending')->firstOrFail();
    expect($instance->status)->toBe('pending')->and($second->assigned_user_id)->toBe($this->carol->id)->and($second->tier_level)->toBe(2);

    wfxActAs($this->carol, $this->tenant);
    wfxDecide($second)->assertOk();

    expect($instance->refresh()->status)->toBe('approved');
});

test('a step is reminded and escalated once, on the schedule of its escalation rule', function (): void {
    $rule = WorkflowEscalationRule::create(['tenant_id' => $this->tenant->id, 'name' => 'Fast', 'reminder_hours' => 1, 'escalation_hours' => 2, 'escalate_to_type' => 'specific_user', 'escalate_to_id' => $this->carol->id, 'auto_action' => 'none']);
    wfxDefinition($this->tenant, 'escalating', ['assignee_type' => 'direct_user', 'assignee_id' => $this->bob->id, 'escalation_rule_id' => $rule->id, 'timeout_hours' => 48]);
    $instance = wfxStart($this->tenant, 'escalating', $this->requester);
    $step = $instance->steps()->firstOrFail();

    expect(array_sum(app(WorkflowTimeoutProcessor::class)->run()))->toBe(0);

    DB::table('workflow_instance_steps')->where('id', $step->id)->update(['created_at' => now()->subHours(3)]);
    $counts = app(WorkflowTimeoutProcessor::class)->run();

    expect($counts)->toBe(['timeouts' => 0, 'auto_decisions' => 0, 'escalations' => 1, 'reminders' => 1]);

    $step->refresh();
    expect($step->assigned_user_id)->toBe($this->carol->id)
        ->and($step->escalation_level)->toBe(1)
        ->and($step->escalated_to_user_id)->toBe($this->carol->id)
        ->and($step->reminded_at)->not->toBeNull()
        ->and($step->due_at->isFuture())->toBeTrue()
        ->and(array_sum(app(WorkflowTimeoutProcessor::class)->run()))->toBe(0);

    expect(WorkflowAuditEntry::query()->where('workflow_instance_id', $instance->id)->pluck('event_type')->all())->toContain('step_reminder_sent', 'step_escalated');

    wfxActAs($this->carol, $this->tenant);
    $task = $this->getJson('/api/v1/workflows/my-tasks')->assertOk()->json('data.0');
    expect($task['step_id'])->toBe($step->id)->and($task['escalation_level'])->toBe(1);

    wfxActAs($this->bob, $this->tenant);
    $this->getJson('/api/v1/workflows/my-tasks')->assertOk()->assertJsonPath('data', []);
});

test('an escalation rule can decide by itself when the time limit runs out', function (): void {
    $rule = WorkflowEscalationRule::create(['tenant_id' => $this->tenant->id, 'name' => 'Auto', 'reminder_hours' => 1, 'escalation_hours' => 2, 'escalate_to_type' => 'reporting_manager', 'auto_action' => 'auto_approve']);
    wfxDefinition($this->tenant, 'auto', ['assignee_type' => 'direct_user', 'assignee_id' => $this->bob->id, 'escalation_rule_id' => $rule->id]);
    $instance = wfxStart($this->tenant, 'auto', $this->requester);
    wfxPastDue($instance->steps()->firstOrFail());

    // The scheduler runs without a signed-in user.
    app('auth')->forgetGuards();
    app(TenantContext::class)->setTenant($this->tenant);
    $counts = app(WorkflowTimeoutProcessor::class)->run();

    $decision = ApprovalDecision::withoutGlobalScopes()->firstOrFail();
    $entry = WorkflowAuditEntry::withoutGlobalScopes()->where('event_type', 'decision_recorded')->firstOrFail();

    expect($counts['auto_decisions'])->toBe(1)
        ->and($instance->refresh()->status)->toBe('approved')
        ->and($decision->decision)->toBe('approved')
        ->and($decision->user_id)->toBe(WorkflowEngineService::SystemActor)
        ->and($entry->event_payload['automatic'])->toBeTrue()
        ->and($entry->actor_user_id)->toBeNull();
});

test('a reporting-manager node goes to the manager of the initiator, and to the administrators without one', function (): void {
    $manager = Employee::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->bob->id]);
    Employee::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->requester->id, 'manager_id' => $manager->id]);
    wfxDefinition($this->tenant, 'manager', ['assignee_type' => 'reporting_manager']);

    $withManager = wfxStart($this->tenant, 'manager', $this->requester)->steps()->firstOrFail();
    $withoutManager = wfxStart($this->tenant, 'manager', $this->carol)->steps()->firstOrFail();

    expect($withManager->assigned_user_id)->toBe($this->bob->id)->and($withManager->assigned_role)->toBeNull()
        ->and($withoutManager->assigned_user_id)->toBeNull()->and($withoutManager->assigned_role)->toBe('admin');

    $this->getJson('/api/v1/workflows/my-tasks')->assertOk()->assertJsonPath('meta.summary.pending', 1);
});

test('an out-of-office delegation brings the tasks of the delegator to the delegate, who decides on their behalf', function (): void {
    wfxDefinition($this->tenant, 'solo', ['assignee_type' => 'direct_user', 'assignee_id' => $this->bob->id]);
    $step = wfxStart($this->tenant, 'solo', $this->requester)->steps()->firstOrFail();

    wfxActAs($this->bob, $this->tenant);
    $this->postJson('/api/v1/workflows/delegations', ['delegate_user_id' => $this->carol->id, 'scope' => 'all', 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addDays(7)->toIso8601String()])->assertCreated();

    wfxActAs($this->carol, $this->tenant);
    $task = $this->getJson('/api/v1/workflows/my-tasks?state=delegated')->assertOk()->json('data.0');
    expect($task['step_id'])->toBe($step->id)->and($task['via_delegation_from'])->toBe($this->bob->id);

    wfxDecide($step)->assertOk();

    $history = $this->getJson('/api/v1/workflows/my-history')->assertOk()->json('data');
    expect($history)->toHaveCount(1)->and($history[0]['on_behalf_of'])->toBeNull();

    wfxActAs($this->bob, $this->tenant);
    expect(ApprovalDecision::query()->firstOrFail()->effective_user_id)->toBe($this->carol->id);
});

test('a delegation covers only the scope its delegator chose', function (): void {
    wfxDefinition($this->tenant, 'solo', ['assignee_type' => 'direct_user', 'assignee_id' => $this->bob->id]);
    $step = wfxStart($this->tenant, 'solo', $this->requester)->steps()->firstOrFail();

    wfxActAs($this->bob, $this->tenant);
    $this->postJson('/api/v1/workflows/delegations', ['delegate_user_id' => $this->carol->id, 'scope' => 'procurement', 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addDays(7)->toIso8601String()])->assertCreated();

    // The record under approval is not a procurement record: the delegation does not reach it.
    wfxActAs($this->carol, $this->tenant);
    $this->getJson('/api/v1/workflows/my-tasks')->assertOk()->assertJsonPath('data', []);
    wfxDecide($step)->assertForbidden();
});

test('delegations are validated: members only, no overlap, no past, no self', function (): void {
    $stranger = wfxUser(complianceTenant('Beta'), 'admin');
    wfxActAs($this->bob, $this->tenant);
    $window = ['starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addDays(3)->toIso8601String(), 'scope' => 'all'];

    $this->postJson('/api/v1/workflows/delegations', [...$window, 'delegate_user_id' => $stranger->id])->assertUnprocessable()->assertJsonValidationErrors(['delegate_user_id']);
    $this->postJson('/api/v1/workflows/delegations', [...$window, 'delegate_user_id' => $this->bob->id])->assertUnprocessable()->assertJsonValidationErrors(['delegate_user_id']);
    $this->postJson('/api/v1/workflows/delegations', [...$window, 'delegate_user_id' => $this->carol->id, 'starts_at' => now()->subDays(5)->toIso8601String(), 'ends_at' => now()->subDay()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors(['ends_at']);
    $this->postJson('/api/v1/workflows/delegations', [...$window, 'delegate_user_id' => $this->carol->id])->assertCreated();
    $this->postJson('/api/v1/workflows/delegations', [...$window, 'delegate_user_id' => $this->admin->id, 'scope' => 'hr'])->assertUnprocessable()->assertJsonValidationErrors(['starts_at']);

    // The delegate cannot hand the work back to the delegator.
    wfxActAs($this->carol, $this->tenant);
    $this->postJson('/api/v1/workflows/delegations', [...$window, 'delegate_user_id' => $this->bob->id])->assertUnprocessable()->assertJsonValidationErrors(['delegate_user_id']);
});

test('the inbox lists what the caller can decide: own steps, role steps, and for administrators every role step', function (): void {
    wfxDefinition($this->tenant, 'role', ['assignee_type' => 'role', 'assignee_id' => 'purchasing']);
    wfxDefinition($this->tenant, 'solo', ['assignee_type' => 'direct_user', 'assignee_id' => $this->carol->id]);
    wfxStart($this->tenant, 'role', $this->requester);
    wfxStart($this->tenant, 'solo', $this->requester);

    wfxActAs($this->bob, $this->tenant);
    $bob = $this->getJson('/api/v1/workflows/my-tasks')->assertOk();
    expect($bob->json('data'))->toHaveCount(1)->and($bob->json('data.0.assigned_role'))->toBe('purchasing')->and($bob->json('meta.summary'))->toBe(['pending' => 1, 'overdue' => 0, 'delegated' => 0, 'escalated' => 0]);

    wfxActAs($this->carol, $this->tenant);
    expect($this->getJson('/api/v1/workflows/my-tasks')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/workflows/my-tasks?definition_code=role')->json('data'))->toHaveCount(0);

    wfxActAs($this->admin, $this->tenant);
    expect($this->getJson('/api/v1/workflows/my-tasks')->json('data'))->toHaveCount(1);

    // The old endpoint is kept, and now follows the role of the membership.
    wfxActAs($this->bob, $this->tenant);
    expect($this->getJson('/api/v1/workflows/my-pending')->assertOk()->json('data'))->toHaveCount(1);
});

test('overdue tasks are flagged and filterable', function (): void {
    wfxDefinition($this->tenant, 'solo', ['assignee_type' => 'direct_user', 'assignee_id' => $this->bob->id]);
    $step = wfxStart($this->tenant, 'solo', $this->requester)->steps()->firstOrFail();
    wfxPastDue($step, 5);

    wfxActAs($this->bob, $this->tenant);
    $task = $this->getJson('/api/v1/workflows/my-tasks?state=overdue')->assertOk()->json('data.0');

    expect($task['is_overdue'])->toBeTrue()->and((float) $task['hours_remaining'])->toBeLessThan(0);
    $this->getJson('/api/v1/workflows/my-tasks?state=bogus')->assertUnprocessable()->assertJsonValidationErrors(['state']);
});

test('my history and my requests list what the caller decided and asked for', function (): void {
    wfxDefinition($this->tenant, 'solo', ['assignee_type' => 'direct_user', 'assignee_id' => $this->bob->id]);
    $instance = wfxStart($this->tenant, 'solo', $this->requester);

    wfxActAs($this->bob, $this->tenant);
    wfxDecide($instance->steps()->firstOrFail(), 'rejected')->assertOk();

    $history = $this->getJson('/api/v1/workflows/my-history?decision=rejected')->assertOk()->json('data');
    expect($history)->toHaveCount(1)->and($history[0]['instance_status'])->toBe('rejected')->and($history[0]['workflow_code'])->toBe('solo');
    $this->getJson('/api/v1/workflows/my-history?decision=approved')->assertOk()->assertJsonPath('data', []);
    $this->getJson('/api/v1/workflows/my-history?decision=maybe')->assertUnprocessable();

    wfxActAs($this->requester, $this->tenant);
    expect($this->getJson('/api/v1/workflows/my-requests')->assertOk()->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/workflows/my-requests?status=approved')->json('data'))->toHaveCount(0);
});

test('the sla report counts on-time, late, timed-out and overdue steps, for administrators only', function (): void {
    wfxDefinition($this->tenant, 'solo', ['assignee_type' => 'direct_user', 'assignee_id' => $this->bob->id]);

    $empty = $this->getJson('/api/v1/workflows/sla-report')->assertOk()->json('data');
    expect($empty['totals']['steps'])->toBe(0)->and($empty['totals']['avg_hours_to_decide'])->toBeNull()->and($empty['totals']['breach_rate_percent'])->toBeNull();

    $onTime = wfxStart($this->tenant, 'solo', $this->requester)->steps()->firstOrFail();
    $late = wfxStart($this->tenant, 'solo', $this->requester)->steps()->firstOrFail();
    $overdue = wfxStart($this->tenant, 'solo', $this->requester)->steps()->firstOrFail();

    wfxActAs($this->bob, $this->tenant);
    wfxDecide($onTime)->assertOk();
    wfxPastDue($late, 2);
    wfxDecide($late)->assertOk();
    wfxPastDue($overdue, 2);

    wfxActAs($this->admin, $this->tenant);
    $report = $this->getJson('/api/v1/workflows/sla-report')->assertOk()->json('data');

    expect($report['totals'])->toMatchArray(['steps' => 3, 'decided_on_time' => 1, 'decided_late' => 1, 'pending_overdue' => 1, 'breached' => 2, 'breach_rate_percent' => '66.67'])
        ->and($report['by_definition'][0]['definition_code'])->toBe('solo')
        ->and($report['totals']['avg_hours_to_decide'])->not->toBeNull();

    wfxActAs($this->bob, $this->tenant);
    $this->getJson('/api/v1/workflows/sla-report')->assertForbidden();
    wfxActAs($this->admin, $this->tenant);
    $this->getJson('/api/v1/workflows/sla-report?from=2020-01-01&to=2027-01-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
});

test('the audit chain verifies and detects a tampered entry', function (): void {
    wfxDefinition($this->tenant, 'solo', ['assignee_type' => 'direct_user', 'assignee_id' => $this->bob->id]);
    $instance = wfxStart($this->tenant, 'solo', $this->requester);
    wfxActAs($this->bob, $this->tenant);
    wfxDecide($instance->steps()->firstOrFail())->assertOk();

    wfxActAs($this->admin, $this->tenant);
    $result = $this->getJson("/api/v1/workflows/instances/{$instance->id}/audit-trail/verify")->assertOk()->json('data');
    $sequences = WorkflowAuditEntry::query()->where('workflow_instance_id', $instance->id)->orderBy('sequence')->pluck('sequence')->all();

    expect($result['is_intact'])->toBeTrue()->and($result['verified'])->toBe($result['entries'])->and($result['legacy_unverifiable'])->toBe(0)
        ->and($sequences)->toBe(range(1, count($sequences)));

    $entry = WorkflowAuditEntry::query()->where('workflow_instance_id', $instance->id)->where('sequence', 2)->firstOrFail();
    DB::table('workflow_audit_entries')->where('id', $entry->id)->update(['event_payload' => json_encode(['forged' => true])]);

    $tampered = $this->getJson("/api/v1/workflows/instances/{$instance->id}/audit-trail/verify")->assertOk()->json('data');
    expect($tampered['is_intact'])->toBeFalse()->and($tampered['first_broken_sequence'])->toBe(2);
});

test('the audit trail and its verification are not reachable across tenants or by outsiders', function (): void {
    wfxDefinition($this->tenant, 'solo', ['assignee_type' => 'direct_user', 'assignee_id' => $this->bob->id]);
    $instance = wfxStart($this->tenant, 'solo', $this->requester);

    wfxActAs(wfxUser(complianceTenant('Beta'), 'admin'), Tenant::query()->where('name', 'Beta')->firstOrFail());
    $this->getJson("/api/v1/workflows/instances/{$instance->id}/audit-trail/verify")->assertNotFound();

    wfxActAs($this->carol, $this->tenant);
    $this->getJson("/api/v1/workflows/instances/{$instance->id}/audit-trail/verify")->assertForbidden();
});

test('a decision retried with the same key answers the same, and a finished approval cannot be decided or cancelled again', function (): void {
    wfxDefinition($this->tenant, 'solo', ['assignee_type' => 'direct_user', 'assignee_id' => $this->bob->id]);
    $instance = wfxStart($this->tenant, 'solo', $this->requester);
    $step = $instance->steps()->firstOrFail();

    wfxActAs($this->bob, $this->tenant);
    wfxDecide($step, 'approved', 'retry-1')->assertOk();
    wfxDecide($step, 'approved', 'retry-1')->assertOk();
    wfxDecide($step, 'approved', 'other-key')->assertConflict();
    expect(ApprovalDecision::query()->count())->toBe(1);

    wfxActAs($this->requester, $this->tenant);
    $this->postJson("/api/v1/workflows/instances/{$instance->id}/cancel")->assertConflict();
    expect($instance->refresh()->status)->toBe('approved');
});

test('escalation rules are managed by administrators, validated, and kept while a node uses them', function (): void {
    $payload = ['name' => 'Standard', 'reminder_hours' => 24, 'escalation_hours' => 48, 'escalate_to_type' => 'specific_role', 'escalate_to_id' => 'admin', 'auto_action' => 'none'];

    $id = $this->postJson('/api/v1/workflows/escalation-rules', $payload)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/workflows/escalation-rules', [...$payload, 'escalation_hours' => 24])->assertUnprocessable()->assertJsonValidationErrors(['escalation_hours']);
    $this->postJson('/api/v1/workflows/escalation-rules', [...$payload, 'escalate_to_type' => 'specific_user', 'escalate_to_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['escalate_to_id']);
    $this->postJson('/api/v1/workflows/escalation-rules', [...$payload, 'escalate_to_id' => null])->assertUnprocessable()->assertJsonValidationErrors(['escalate_to_id']);
    $this->postJson('/api/v1/workflows/escalation-rules', [...$payload, 'auto_action' => 'auto_everything'])->assertUnprocessable()->assertJsonValidationErrors(['auto_action']);
    $this->putJson("/api/v1/workflows/escalation-rules/{$id}", [...$payload, 'name' => 'Renamed', 'auto_action' => 'auto_reject'])->assertOk()->assertJsonPath('data.auto_action', 'auto_reject');
    expect($this->getJson('/api/v1/workflows/escalation-rules')->json('data'))->toHaveCount(1);

    wfxDefinition($this->tenant, 'solo', ['assignee_type' => 'direct_user', 'assignee_id' => $this->bob->id, 'escalation_rule_id' => $id]);
    $this->deleteJson("/api/v1/workflows/escalation-rules/{$id}")->assertConflict();

    wfxActAs($this->carol, $this->tenant);
    expect($this->getJson('/api/v1/workflows/escalation-rules')->assertOk()->json('data'))->toHaveCount(1);
    $this->postJson('/api/v1/workflows/escalation-rules', $payload)->assertForbidden();
    $this->putJson("/api/v1/workflows/escalation-rules/{$id}", $payload)->assertForbidden();
    $this->deleteJson("/api/v1/workflows/escalation-rules/{$id}")->assertForbidden();

    wfxActAs(wfxUser(complianceTenant('Beta'), 'admin'), Tenant::query()->where('name', 'Beta')->firstOrFail());
    $this->putJson("/api/v1/workflows/escalation-rules/{$id}", $payload)->assertNotFound();
    $this->deleteJson("/api/v1/workflows/escalation-rules/{$id}")->assertNotFound();
    expect($this->getJson('/api/v1/workflows/escalation-rules')->json('data'))->toBe([]);
});

test('nodes take only escalation rules and approvers of their own company', function (): void {
    $definition = $this->postJson('/api/v1/workflows/definitions', ['code' => 'DRAFT_FLOW', 'name' => 'Draft flow', 'target_entity_type' => Contact::class])->assertCreated()->json('id');
    $foreignTenant = complianceTenant('Beta');
    $foreignRule = WorkflowEscalationRule::create(['tenant_id' => $foreignTenant->id, 'name' => 'Foreign', 'reminder_hours' => 1, 'escalation_hours' => 2, 'escalate_to_type' => 'specific_role', 'escalate_to_id' => 'admin', 'auto_action' => 'none']);
    $outsider = wfxUser($foreignTenant, 'admin');
    $node = ['node_key' => 'review', 'name' => 'Review', 'node_type' => 'approval', 'approval_type' => 'and_parallel', 'assignee_type' => 'user_list', 'assignee_id' => "{$this->bob->id},{$this->carol->id}"];

    $this->postJson("/api/v1/workflows/definitions/{$definition}/nodes", [...$node, 'escalation_rule_id' => $foreignRule->id])->assertUnprocessable()->assertJsonValidationErrors(['escalation_rule_id']);
    $this->postJson("/api/v1/workflows/definitions/{$definition}/nodes", [...$node, 'assignee_id' => "{$this->bob->id},{$outsider->id}"])->assertUnprocessable()->assertJsonValidationErrors(['assignee_id']);
    $this->postJson("/api/v1/workflows/definitions/{$definition}/nodes", [...$node, 'assignee_type' => 'direct_user', 'assignee_id' => "{$this->bob->id},{$this->carol->id}"])->assertUnprocessable()->assertJsonValidationErrors(['assignee_id']);

    $own = $this->postJson('/api/v1/workflows/escalation-rules', ['name' => 'Own', 'reminder_hours' => 1, 'escalation_hours' => 2, 'escalate_to_type' => 'reporting_manager'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/workflows/definitions/{$definition}/nodes", [...$node, 'escalation_rule_id' => $own])->assertCreated()->assertJsonPath('data.escalation_rule_id', $own);
});
