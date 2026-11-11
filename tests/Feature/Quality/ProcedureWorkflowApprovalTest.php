<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Documents\Models\DmsDocument;
use Modules\Quality\Listeners\WorkflowApprovalQualityListener;
use Modules\Quality\Models\QmsProcedure;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceExpiredEvent;
use Modules\Workflow\Models\WorkflowAuditEntry;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowEscalationRule;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;
use Modules\Workflow\Services\WorkflowDefinitionManager;
use Modules\Workflow\Services\WorkflowEngineService;
use Modules\Workflow\Services\WorkflowTimeoutProcessor;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-05-12 09:00:00');
    $this->tenant = pwfTenant('Qualita Verdi');
    $this->author = pwfUser($this->tenant, 'owner');
    $this->first = pwfUser($this->tenant, 'admin');
    $this->second = pwfUser($this->tenant, 'admin');
    $this->third = pwfUser($this->tenant, 'admin');
    $this->document = pwfDocument($this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function pwfTenant(string $name): Tenant
{
    return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
}

function pwfUser(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@pwf-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'it']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function pwfAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', (string) $tenant->id);
}

function pwfDocument(Tenant $tenant): DmsDocument
{
    return app(TenantContext::class)->runWithTenant($tenant, fn (): DmsDocument => DmsDocument::create(['tenant_id' => $tenant->id, 'title' => 'Procedura', 'status' => 'draft', 'current_version' => 1, 'access_roles' => [], 'metadata' => [], 'search_text' => '', 'legal_hold' => false]));
}

/**
 * Creates and publishes the definition through the Workflow module's own service: start, one approval node per
 * assignee in sequence, end. Rejection ends the instance as rejected.
 *
 * @param  list<string>  $assigneeIds
 */
function pwfDefinition(Tenant $tenant, array $assigneeIds, ?string $escalationRuleId = null, bool $publish = true, string $code = QmsProcedure::ApprovalWorkflow): WorkflowDefinition
{
    $nodes = [['node_key' => 'start', 'name' => 'Start', 'node_type' => 'start']];
    $transitions = [];
    $previous = 'start';
    foreach ($assigneeIds as $index => $assigneeId) {
        $key = 'step_'.($index + 1);
        $nodes[] = array_filter(['node_key' => $key, 'name' => "Approval {$key}", 'node_type' => 'approval', 'approval_type' => 'single', 'assignee_type' => 'direct_user', 'assignee_id' => $assigneeId, 'timeout_hours' => 24, 'escalation_rule_id' => $escalationRuleId]);
        $transitions[] = ['from' => $previous, 'to' => $key, 'action_trigger' => $previous === 'start' ? 'start' : 'approved'];
        $previous = $key;
    }

    $nodes[] = ['node_key' => 'end', 'name' => 'End', 'node_type' => 'end'];
    $transitions[] = ['from' => $previous, 'to' => 'end', 'action_trigger' => 'approved'];

    return app(WorkflowDefinitionManager::class)->create((string) $tenant->id, [
        'code' => $code,
        'name' => 'Approvazione procedure',
        'target_entity_type' => QmsProcedure::class,
        'nodes' => $nodes,
        'transitions' => $transitions,
        'publish' => $publish,
    ], null);
}

/**
 * A draft procedure with its document, created by the author through the Quality API.
 */
function pwfProcedure(string $code = 'PRO-100'): array
{
    return test()->postJson('/api/v1/quality/procedures', ['code' => $code, 'title' => 'Gestione dei fornitori', 'document_id' => test()->document->id])->assertCreated()->json('data');
}

function pwfSubmit(array $procedure): array
{
    return test()->postJson("/api/v1/quality/procedures/{$procedure['id']}/submit")->assertOk()->json('data');
}

function pwfPendingStep(string $workflowInstanceId): WorkflowInstanceStep
{
    return WorkflowInstanceStep::withoutGlobalScopes()->where('workflow_instance_id', $workflowInstanceId)->where('status', 'pending')->firstOrFail();
}

function pwfDecide(WorkflowInstanceStep $step, string $decision = 'approved')
{
    return test()->postJson("/api/v1/workflows/steps/{$step->id}/decide", ['decision' => $decision]);
}

function pwfProcedureStatus(string $id): string
{
    return (string) QmsProcedure::withoutGlobalScopes()->whereKey($id)->value('status');
}

test('a multi-step approval workflow approves the procedure only after the last approver, who is recorded as its approver', function (): void {
    pwfDefinition($this->tenant, [$this->first->id, $this->second->id]);
    pwfAct($this->author, $this->tenant);
    $procedure = pwfProcedure();

    $submitted = pwfSubmit($procedure);
    expect($submitted['status'])->toBe('pending_approval')->and($submitted['workflow_instance_id'])->not->toBeNull();
    $instanceId = $submitted['workflow_instance_id'];

    // while the workflow runs the direct decision is closed, to everybody
    pwfAct($this->third, $this->tenant);
    $this->postJson("/api/v1/quality/procedures/{$procedure['id']}/approve")->assertUnprocessable();
    $this->postJson("/api/v1/quality/procedures/{$procedure['id']}/reject", ['reason' => 'No'])->assertUnprocessable();

    // the first step belongs to the first approver only
    $stepOne = pwfPendingStep($instanceId);
    expect($stepOne->assigned_user_id)->toBe($this->first->id);
    pwfDecide($stepOne)->assertForbidden();
    pwfAct($this->first, $this->tenant);
    pwfDecide($stepOne)->assertOk();
    expect(pwfProcedureStatus($procedure['id']))->toBe('pending_approval');

    // the second step now waits for the second approver; the first cannot decide again
    $stepTwo = pwfPendingStep($instanceId);
    expect($stepTwo->assigned_user_id)->toBe($this->second->id);
    pwfDecide($stepTwo)->assertForbidden();
    pwfAct($this->second, $this->tenant);
    pwfDecide($stepTwo)->assertOk();

    $approved = QmsProcedure::withoutGlobalScopes()->findOrFail($procedure['id']);
    expect($approved->status)->toBe('approved')
        ->and($approved->approved_by)->toBe($this->second->id)
        ->and($approved->effective_date->toDateString())->toBe('2027-05-12')
        ->and(WorkflowInstance::withoutGlobalScopes()->findOrFail($instanceId)->status)->toBe('approved');

    $ledger = AuditLedgerEntry::query()->where('tenant_id', $this->tenant->id)->where('module_code', 'MODULE_40_QUALITY')->pluck('action')->all();
    expect($ledger)->toContain('QMS_PROCEDURE_SUBMITTED', 'QMS_PROCEDURE_APPROVED');
    $workflowEvents = WorkflowAuditEntry::withoutGlobalScopes()->where('workflow_instance_id', $instanceId)->pluck('event_type')->all();
    expect($workflowEvents)->toContain('instance_started', 'decision_recorded');
    expect(array_count_values($workflowEvents)['decision_recorded'])->toBe(2);
});

test('a new version approved through the workflow makes the previous version obsolete', function (): void {
    pwfDefinition($this->tenant, [$this->first->id]);
    pwfAct($this->author, $this->tenant);
    $v1 = pwfProcedure();
    $instance = pwfSubmit($v1)['workflow_instance_id'];
    pwfAct($this->first, $this->tenant);
    pwfDecide(pwfPendingStep($instance))->assertOk();
    expect(pwfProcedureStatus($v1['id']))->toBe('approved');

    pwfAct($this->author, $this->tenant);
    $v2 = $this->postJson("/api/v1/quality/procedures/{$v1['id']}/revise")->assertCreated()->json('data');
    expect($v2['version'])->toBe(2);
    $instance2 = pwfSubmit($v2)['workflow_instance_id'];
    expect($instance2)->not->toBe($instance);
    // until the new version is approved the old one stays effective
    expect(pwfProcedureStatus($v1['id']))->toBe('approved');

    pwfAct($this->first, $this->tenant);
    pwfDecide(pwfPendingStep($instance2))->assertOk();

    expect(QmsProcedure::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('code', 'PRO-100')->orderBy('version')->pluck('status')->all())->toBe(['obsolete', 'approved']);
    pwfAct($this->author, $this->tenant);
    expect($this->getJson('/api/v1/quality/procedures?effective=1')->assertOk()->json('data'))->toHaveCount(1);
});

test('a rejection in the workflow rejects the procedure at any step and the author can submit it again', function (): void {
    pwfDefinition($this->tenant, [$this->first->id, $this->second->id]);
    pwfAct($this->author, $this->tenant);
    $procedure = pwfProcedure();
    $instance = pwfSubmit($procedure)['workflow_instance_id'];

    pwfAct($this->first, $this->tenant);
    pwfDecide(pwfPendingStep($instance))->assertOk();
    pwfAct($this->second, $this->tenant);
    pwfDecide(pwfPendingStep($instance), 'rejected')->assertOk();

    $rejected = QmsProcedure::withoutGlobalScopes()->findOrFail($procedure['id']);
    expect($rejected->status)->toBe('rejected')
        ->and($rejected->rejected_by)->toBe($this->second->id)
        ->and($rejected->rejection_reason)->toBe('Rejected by workflow')
        ->and(WorkflowInstance::withoutGlobalScopes()->findOrFail($instance)->status)->toBe('rejected');

    // a new submission starts a new instance, which can approve it
    pwfAct($this->author, $this->tenant);
    $again = pwfSubmit($procedure);
    expect($again['status'])->toBe('pending_approval')->and($again['workflow_instance_id'])->not->toBe($instance);
    pwfAct($this->first, $this->tenant);
    pwfDecide(pwfPendingStep($again['workflow_instance_id']))->assertOk();
    pwfAct($this->second, $this->tenant);
    pwfDecide(pwfPendingStep($again['workflow_instance_id']))->assertOk();
    expect(pwfProcedureStatus($procedure['id']))->toBe('approved');
});

test('the author can never approve, as assignee, as delegate or by submitting through someone else', function (): void {
    // the author is named approver of the first step
    pwfDefinition($this->tenant, [$this->author->id]);
    pwfAct($this->author, $this->tenant);
    $procedure = pwfProcedure();
    $instance = pwfSubmit($procedure)['workflow_instance_id'];
    $step = pwfPendingStep($instance);

    pwfDecide($step)->assertForbidden();
    expect(pwfProcedureStatus($procedure['id']))->toBe('pending_approval');
    $this->postJson("/api/v1/quality/procedures/{$procedure['id']}/approve")->assertUnprocessable();
});

test('the author cannot approve as the delegate of an approver', function (): void {
    pwfDefinition($this->tenant, [$this->first->id]);
    pwfAct($this->author, $this->tenant);
    $procedure = pwfProcedure();
    $step = pwfPendingStep(pwfSubmit($procedure)['workflow_instance_id']);

    // the approver delegates to the author, who then decides in their place: refused by the four-eyes rule
    pwfAct($this->first, $this->tenant);
    $this->postJson('/api/v1/workflows/delegations', ['delegate_user_id' => $this->author->id, 'scope' => 'all', 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addDays(7)->toIso8601String()])->assertCreated();
    pwfAct($this->author, $this->tenant);
    pwfDecide($step)->assertForbidden();

    expect(pwfProcedureStatus($procedure['id']))->toBe('pending_approval');
});

test('whoever submits the procedure for someone else is bound by the four-eyes rule too', function (): void {
    $colleague = pwfUser($this->tenant, 'admin');
    pwfDefinition($this->tenant, [$colleague->id]);
    pwfAct($this->author, $this->tenant);
    $procedure = pwfProcedure();

    pwfAct($colleague, $this->tenant);
    $step = pwfPendingStep(pwfSubmit($procedure)['workflow_instance_id']);
    pwfDecide($step)->assertForbidden();

    expect(pwfProcedureStatus($procedure['id']))->toBe('pending_approval');
});

test('an approver can delegate and the delegate approves in their place', function (): void {
    pwfDefinition($this->tenant, [$this->first->id]);
    pwfAct($this->author, $this->tenant);
    $procedure = pwfProcedure();
    $instance = pwfSubmit($procedure)['workflow_instance_id'];

    pwfAct($this->first, $this->tenant);
    $this->postJson('/api/v1/workflows/delegations', ['delegate_user_id' => $this->third->id, 'scope' => 'all', 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addDays(7)->toIso8601String()])->assertCreated();

    // someone without delegation cannot, the delegate can
    pwfAct($this->second, $this->tenant);
    pwfDecide(pwfPendingStep($instance))->assertForbidden();
    pwfAct($this->third, $this->tenant);
    pwfDecide(pwfPendingStep($instance))->assertOk();

    $approved = QmsProcedure::withoutGlobalScopes()->findOrFail($procedure['id']);
    expect($approved->status)->toBe('approved')->and($approved->approved_by)->toBe($this->third->id);
});

test('escalation moves the step to another approver, who decides in place of the first', function (): void {
    $escalate = WorkflowEscalationRule::create(['tenant_id' => $this->tenant->id, 'name' => 'Escalate', 'reminder_hours' => 1, 'escalation_hours' => 2, 'escalate_to_type' => 'specific_user', 'escalate_to_id' => $this->third->id, 'auto_action' => 'none']);
    pwfDefinition($this->tenant, [$this->first->id], $escalate->id);
    pwfAct($this->author, $this->tenant);
    $escalated = pwfProcedure('PRO-ESC');
    $instance = pwfSubmit($escalated)['workflow_instance_id'];
    $step = pwfPendingStep($instance);

    WorkflowInstanceStep::query()->whereKey($step->id)->update(['created_at' => now()->subHours(3)]);
    app(WorkflowTimeoutProcessor::class)->run();
    expect($step->refresh()->assigned_user_id)->toBe($this->third->id);

    pwfAct($this->first, $this->tenant);
    pwfDecide($step)->assertForbidden();
    pwfAct($this->third, $this->tenant);
    pwfDecide($step)->assertOk();
    expect(pwfProcedureStatus($escalated['id']))->toBe('approved');
});

test('an automatic rejection by the escalation rule rejects the procedure, an automatic approval leaves it pending for a person', function (): void {
    $autoReject = WorkflowEscalationRule::create(['tenant_id' => $this->tenant->id, 'name' => 'Auto reject', 'reminder_hours' => 1, 'escalation_hours' => 2, 'escalate_to_type' => 'reporting_manager', 'auto_action' => 'auto_reject']);
    $definition = pwfDefinition($this->tenant, [$this->first->id], $autoReject->id);
    pwfAct($this->author, $this->tenant);
    $rejectedProcedure = pwfProcedure('PRO-AUTO-R');
    $step = pwfPendingStep(pwfSubmit($rejectedProcedure)['workflow_instance_id']);
    WorkflowInstanceStep::query()->whereKey($step->id)->update(['due_at' => now()->subHour()]);

    app('auth')->forgetGuards();
    app(TenantContext::class)->setTenant($this->tenant);
    app(WorkflowTimeoutProcessor::class)->run();

    $rejected = QmsProcedure::withoutGlobalScopes()->findOrFail($rejectedProcedure['id']);
    expect($rejected->status)->toBe('rejected')->and($rejected->rejected_by)->toBeNull();

    // switch the rule to automatic approval on the same definition
    $autoReject->update(['auto_action' => 'auto_approve']);
    pwfAct($this->author, $this->tenant);
    $autoProcedure = pwfProcedure('PRO-AUTO-A');
    $submitted = pwfSubmit($autoProcedure);
    $step = pwfPendingStep($submitted['workflow_instance_id']);
    WorkflowInstanceStep::query()->whereKey($step->id)->update(['due_at' => now()->subHour()]);
    app('auth')->forgetGuards();
    app(TenantContext::class)->setTenant($this->tenant);
    app(WorkflowTimeoutProcessor::class)->run();

    expect(WorkflowInstance::withoutGlobalScopes()->findOrFail($submitted['workflow_instance_id'])->status)->toBe('approved')
        ->and(pwfProcedureStatus($autoProcedure['id']))->toBe('pending_approval')
        ->and($definition->is_active)->toBeTrue();

    // the workflow is over, so the procedure can be decided directly by a person who is not its author
    pwfAct($this->third, $this->tenant);
    $this->postJson("/api/v1/quality/procedures/{$autoProcedure['id']}/approve")->assertOk()->assertJsonPath('data.approved_by', $this->third->id);
});

test('without an active definition the procedure waits for a direct decision; a cancelled workflow opens it too', function (): void {
    pwfAct($this->author, $this->tenant);

    // no definition at all
    $plain = pwfProcedure('PRO-NODEF');
    $submitted = pwfSubmit($plain);
    expect($submitted['workflow_instance_id'])->toBeNull()->and($submitted['status'])->toBe('pending_approval');
    pwfAct($this->first, $this->tenant);
    $this->postJson("/api/v1/quality/procedures/{$plain['id']}/approve")->assertOk()->assertJsonPath('data.approved_by', $this->first->id);

    // a definition that is not published or not active is ignored
    $draft = pwfDefinition($this->tenant, [$this->first->id], null, false);
    expect($draft->is_active)->toBeFalse();
    pwfAct($this->author, $this->tenant);
    $second = pwfProcedure('PRO-INACTIVE');
    expect(pwfSubmit($second)['workflow_instance_id'])->toBeNull();

    // once it is published the next submission uses it, and cancelling the instance gives the decision back to people
    app(WorkflowDefinitionManager::class)->publish($draft);
    expect($draft->refresh()->is_active)->toBeTrue();
    $third = pwfProcedure('PRO-CANCEL');
    $instance = pwfSubmit($third)['workflow_instance_id'];
    expect($instance)->not->toBeNull();
    app(WorkflowEngineService::class)->cancel(WorkflowInstance::withoutGlobalScopes()->findOrFail($instance), 'Withdrawn');

    pwfAct($this->second, $this->tenant);
    $this->postJson("/api/v1/quality/procedures/{$third['id']}/approve")->assertOk();
});

test('a broken definition is reported to the author and leaves the procedure a draft', function (): void {
    WorkflowDefinition::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'company_id' => $this->tenant->id, 'code' => QmsProcedure::ApprovalWorkflow, 'name' => 'Broken', 'target_entity_type' => QmsProcedure::class, 'version' => 1, 'status' => 'published', 'is_active' => true]);
    pwfAct($this->author, $this->tenant);
    $procedure = pwfProcedure('PRO-BROKEN');

    $this->postJson("/api/v1/quality/procedures/{$procedure['id']}/submit")->assertUnprocessable();
    expect(pwfProcedureStatus($procedure['id']))->toBe('draft')
        ->and(QmsProcedure::withoutGlobalScopes()->whereKey($procedure['id'])->value('workflow_instance_id'))->toBeNull();
});

test('another company cannot act on the workflow or on the procedure, and its definition never decides ours', function (): void {
    pwfDefinition($this->tenant, [$this->first->id]);
    $other = pwfTenant('Concorrente');
    $outsider = pwfUser($other, 'admin');
    $outsiderOwner = pwfUser($other, 'owner');
    // the other company has its own definition with its own approver
    pwfDefinition($other, [$outsider->id]);

    pwfAct($this->author, $this->tenant);
    $procedure = pwfProcedure();
    $instance = pwfSubmit($procedure)['workflow_instance_id'];
    $step = pwfPendingStep($instance);

    pwfAct($outsider, $other);
    pwfDecide($step)->assertNotFound();
    $this->postJson("/api/v1/quality/procedures/{$procedure['id']}/approve")->assertNotFound();
    $this->postJson("/api/v1/quality/procedures/{$procedure['id']}/reject", ['reason' => 'Intruso'])->assertNotFound();
    pwfAct($outsiderOwner, $other);
    $this->postJson("/api/v1/quality/procedures/{$procedure['id']}/submit")->assertNotFound();

    // a user of this company cannot act in the other company by naming it
    pwfAct($this->first, $other);
    $this->getJson('/api/v1/quality/procedures')->assertForbidden();

    // an event for our instance delivered in the name of the other company changes nothing
    app(WorkflowApprovalQualityListener::class)->handleCompleted(new WorkflowInstanceCompletedEvent($instance, 'approved', (string) $other->id, (string) $other->id));
    expect(pwfProcedureStatus($procedure['id']))->toBe('pending_approval');

    // and the instance of the other company's definition is never ours
    expect(WorkflowInstance::withoutGlobalScopes()->findOrFail($instance)->tenant_id)->toBe($this->tenant->id);
});

test('an expired approval workflow takes the procedure back to draft, never approved or rejected, and it can be submitted again', function (): void {
    pwfDefinition($this->tenant, [$this->first->id]);
    pwfAct($this->author, $this->tenant);
    $procedure = pwfProcedure('PRO-EXP');
    $instance = pwfSubmit($procedure)['workflow_instance_id'];
    $step = pwfPendingStep($instance);

    // nobody decides in time and the definition has no timeout path: the engine expires the instance
    app(WorkflowEngineService::class)->handleStepTimeout($step);
    expect(WorkflowInstance::withoutGlobalScopes()->findOrFail($instance)->status)->toBe('expired');

    $draft = QmsProcedure::withoutGlobalScopes()->findOrFail($procedure['id']);
    expect($draft->status)->toBe('draft')
        ->and($draft->submitted_at)->toBeNull()
        ->and($draft->approved_by)->toBeNull()
        ->and($draft->rejected_by)->toBeNull()
        ->and(AuditLedgerEntry::query()->where('tenant_id', $this->tenant->id)->where('action', 'QMS_PROCEDURE_APPROVAL_EXPIRED')->count())->toBe(1);

    // a late decision on the expired step is refused, and the author starts again
    pwfAct($this->first, $this->tenant);
    pwfDecide($step)->assertStatus(409);
    pwfAct($this->author, $this->tenant);
    $again = pwfSubmit($procedure);
    expect($again['status'])->toBe('pending_approval')->and($again['workflow_instance_id'])->not->toBe($instance);

    // a redelivered expiry event changes nothing once the procedure is not pending any more
    pwfAct($this->first, $this->tenant);
    pwfDecide(pwfPendingStep($again['workflow_instance_id']))->assertOk();
    app(WorkflowApprovalQualityListener::class)->handleExpired(new WorkflowInstanceExpiredEvent($instance, 'no_decision_in_time', (string) $this->tenant->id, (string) $this->tenant->id));
    expect(pwfProcedureStatus($procedure['id']))->toBe('approved');
});
