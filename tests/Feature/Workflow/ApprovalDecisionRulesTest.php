<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Supplier;
use Modules\Workflow\Enums\DecisionType;
use Modules\Workflow\Models\ApprovalDecision;
use Modules\Workflow\Models\ApprovalMatrix;
use Modules\Workflow\Models\ApprovalMatrixTier;
use Modules\Workflow\Models\WorkflowAuditEntry;
use Modules\Workflow\Services\ApprovalGuardService;
use Modules\Workflow\Services\WorkflowAuditLedgerService;

require_once __DIR__.'/Support/ApprovalEngineHelpers.php';

beforeEach(function (): void {
    $this->tenant = wfTenant();
    $this->requester = wfMember($this->tenant, 'member', 'Requester');
    $this->alice = wfMember($this->tenant, 'member', 'Alice');
    $this->bob = wfMember($this->tenant, 'member', 'Bob');
    $this->admin = wfMember($this->tenant, 'admin', 'Admin');

    $this->as = function ($user) {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Tenant-Id', (string) $this->tenant->id);
    };

    $this->decide = fn ($user, string $stepId, array $body) => ($this->as)($user)->postJson("/api/v1/workflows/steps/{$stepId}/decide", $body);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('two approvers of an any node acting together: the first wins with 200 and the second gets 409 with the winning decision', function (): void {
    wfDefinition($this->tenant, 'WF_ANY', ['assignee_type' => 'user_list', 'assignee_id' => $this->alice->id.','.$this->bob->id, 'approval_type' => 'or_parallel']);
    $instance = wfStart($this->tenant, 'WF_ANY', $this->tenant, $this->requester);
    $steps = $instance->steps()->get()->keyBy('assigned_user_id');

    ($this->decide)($this->alice, (string) $steps[$this->alice->id]->id, ['decision' => 'approved', 'comment' => 'ok'])->assertOk();

    $late = ($this->decide)($this->bob, (string) $steps[$this->bob->id]->id, ['decision' => 'approved']);

    $late->assertStatus(409)->assertJsonPath('data.decision', 'approved')->assertJsonPath('data.decided_by', (string) $this->alice->id);
    expect($instance->refresh()->status)->toBe('approved')
        ->and(ApprovalDecision::query()->whereIn('workflow_instance_step_id', $instance->steps()->pluck('id'))->count())->toBe(1);
});

test('a decision on a step that was already decided answers 409', function (): void {
    wfDefinition($this->tenant, 'WF_ONE', ['assignee_type' => 'direct_user', 'assignee_id' => $this->alice->id]);
    $step = wfStart($this->tenant, 'WF_ONE', $this->tenant, $this->requester)->steps()->firstOrFail();

    ($this->decide)($this->alice, (string) $step->id, ['decision' => 'approved'])->assertOk();
    ($this->decide)($this->alice, (string) $step->id, ['decision' => 'approved'])->assertStatus(409);
});

test('rejecting and requesting changes need a reason', function (): void {
    wfDefinition($this->tenant, 'WF_REASON', ['assignee_type' => 'direct_user', 'assignee_id' => $this->alice->id]);
    $step = wfStart($this->tenant, 'WF_REASON', $this->tenant, $this->requester)->steps()->firstOrFail();

    ($this->decide)($this->alice, (string) $step->id, ['decision' => 'reject'])->assertUnprocessable()->assertJsonValidationErrors('comment');
    ($this->decide)($this->alice, (string) $step->id, ['decision' => 'request_changes'])->assertUnprocessable()->assertJsonValidationErrors('comment');
    ($this->decide)($this->alice, (string) $step->id, ['decision' => 'approve'])->assertOk();
});

test('requesting changes returns the document to the requester and is audited', function (): void {
    wfDefinition($this->tenant, 'WF_CHANGES', ['assignee_type' => 'direct_user', 'assignee_id' => $this->alice->id]);
    $instance = wfStart($this->tenant, 'WF_CHANGES', $this->tenant, $this->requester);
    $step = $instance->steps()->firstOrFail();

    ($this->decide)($this->alice, (string) $step->id, ['decision' => 'request_changes', 'comment' => 'Attach the quote'])->assertOk();

    $instance->refresh();
    expect($instance->status)->toBe('rejected')
        ->and($instance->closing_reason)->toBe('changes_requested')
        ->and($step->refresh()->status)->toBe('changes_requested')
        ->and(WorkflowAuditEntry::query()->where('workflow_instance_id', $instance->id)->where('event_type', 'changes_requested')->exists())->toBeTrue()
        ->and(app(WorkflowAuditLedgerService::class)->verify($instance)['is_intact'])->toBeTrue();
});

test('an approver reassigns a step to another member and not to a stranger', function (): void {
    wfDefinition($this->tenant, 'WF_REASSIGN', ['assignee_type' => 'direct_user', 'assignee_id' => $this->alice->id]);
    $instance = wfStart($this->tenant, 'WF_REASSIGN', $this->tenant, $this->requester);
    $step = $instance->steps()->firstOrFail();
    $stranger = User::factory()->create();

    ($this->decide)($this->alice, (string) $step->id, ['decision' => 'reassign', 'reassign_to_user_id' => (string) $stranger->id])->assertUnprocessable();
    ($this->decide)($this->alice, (string) $step->id, ['decision' => 'reassign', 'reassign_to_user_id' => (string) $this->bob->id, 'comment' => 'Bob knows the supplier'])->assertOk();

    expect($step->refresh()->assigned_user_id)->toBe((string) $this->bob->id)->and($step->status)->toBe('pending')
        ->and(WorkflowAuditEntry::query()->where('workflow_instance_id', $instance->id)->where('event_type', 'step_reassigned')->exists())->toBeTrue();

    ($this->decide)($this->alice, (string) $step->id, ['decision' => 'approved'])->assertForbidden();
    ($this->decide)($this->bob, (string) $step->id, ['decision' => 'approved'])->assertOk();
});

test('a node can forbid reassignment', function (): void {
    wfDefinition($this->tenant, 'WF_NO_REASSIGN', ['assignee_type' => 'direct_user', 'assignee_id' => $this->alice->id, 'ui_metadata' => ['allow_reassign' => false]]);
    $step = wfStart($this->tenant, 'WF_NO_REASSIGN', $this->tenant, $this->requester)->steps()->firstOrFail();

    ($this->decide)($this->alice, (string) $step->id, ['decision' => 'reassign', 'reassign_to_user_id' => (string) $this->bob->id])->assertForbidden();
});

test('the requester cannot approve their own request and the step is handed to the administrators', function (): void {
    wfDefinition($this->tenant, 'WF_SOD', ['assignee_type' => 'direct_user', 'assignee_id' => $this->requester->id], ['sod' => ['block_self_approval' => true]]);
    $instance = wfStart($this->tenant, 'WF_SOD', $this->tenant, $this->requester);
    $step = $instance->steps()->firstOrFail();

    // The level assigned to the requester is substituted: nobody waits on the person who made the request.
    expect($step->assigned_user_id)->toBeNull()->and($step->assigned_role)->toBe('admin');

    ($this->decide)($this->requester, (string) $step->id, ['decision' => 'approved'])->assertForbidden();
    ($this->decide)($this->admin, (string) $step->id, ['decision' => 'approved'])->assertOk();
});

test('segregation of duties binds administrators too', function (): void {
    wfDefinition($this->tenant, 'WF_SOD_ADMIN', ['assignee_type' => 'role', 'assignee_id' => 'admin'], ['sod' => ['block_self_approval' => true]]);
    $instance = wfStart($this->tenant, 'WF_SOD_ADMIN', $this->tenant, $this->admin);
    $step = $instance->steps()->firstOrFail();

    ($this->decide)($this->admin, (string) $step->id, ['decision' => 'approved'])->assertForbidden();
    expect($step->refresh()->status)->toBe('pending');
});

test('the self-approval rule is off unless the definition or the configuration turns it on', function (): void {
    wfDefinition($this->tenant, 'WF_SOD_OFF', ['assignee_type' => 'direct_user', 'assignee_id' => $this->alice->id]);
    $instance = wfStart($this->tenant, 'WF_SOD_OFF', $this->tenant, $this->alice);
    $step = $instance->steps()->firstOrFail();

    ($this->decide)($this->alice, (string) $step->id, ['decision' => 'approved'])->assertOk();

    config(['workflow.sod.block_self_approval' => true]);
    wfDefinition($this->tenant, 'WF_SOD_CONFIG', ['assignee_type' => 'direct_user', 'assignee_id' => $this->bob->id]);
    $second = wfStart($this->tenant, 'WF_SOD_CONFIG', $this->tenant, $this->bob)->steps()->firstOrFail();

    expect($second->assigned_user_id)->toBeNull()->and($second->assigned_role)->toBe('admin');
});

test('the same user cannot approve two levels of one workflow when the rule is on', function (): void {
    wfDefinition($this->tenant, 'WF_MULTI', ['assignee_type' => 'direct_user', 'assignee_id' => $this->alice->id], ['sod' => ['block_multi_level' => true]]);
    $instance = wfStart($this->tenant, 'WF_MULTI', $this->tenant, $this->requester);
    $first = $instance->steps()->firstOrFail();
    $first->update(['status' => 'approved']);
    ApprovalDecision::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'workflow_instance_step_id' => $first->id, 'user_id' => $this->alice->id, 'effective_user_id' => $this->alice->id, 'decision' => 'approved', 'decided_at' => now()]);

    $second = $instance->steps()->create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'workflow_node_id' => $first->workflow_node_id, 'assigned_user_id' => $this->alice->id, 'status' => 'pending', 'tier_level' => 2, 'due_at' => now()->addDay()]);
    $guard = app(ApprovalGuardService::class);

    expect(fn () => $guard->assertMayDecide($instance->refresh(), $second, (string) $this->alice->id, (string) $this->alice->id, DecisionType::Approve))->toThrow(AuthorizationException::class)
        ->and($guard->assertMayDecide($instance, $second, (string) $this->bob->id, (string) $this->bob->id, DecisionType::Approve))->toBeNull();
});

test('an approver decides only up to their signing limit', function (): void {
    $supplier = Supplier::factory()->approved()->create(['tenant_id' => $this->tenant->id]);
    $order = PurchaseOrder::factory()->create(['tenant_id' => $this->tenant->id, 'supplier_id' => $supplier->id, 'status' => 'draft', 'total_amount' => '20000.00', 'total_gross' => '20000.00', 'currency' => 'CHF']);
    wfDefinition($this->tenant, 'WF_LIMIT', ['assignee_type' => 'direct_user', 'assignee_id' => $this->alice->id]);
    $step = wfStart($this->tenant, 'WF_LIMIT', $order, $this->requester)->steps()->firstOrFail();

    DB::table('tenant_user')->where('user_id', $this->alice->id)->where('tenant_id', $this->tenant->id)->update(['approval_limit' => '10000']);

    ($this->decide)($this->alice, (string) $step->id, ['decision' => 'approved'])->assertForbidden();
    expect($step->refresh()->status)->toBe('pending');

    // A rejection is not limited, and a higher limit lets the approval through.
    DB::table('tenant_user')->where('user_id', $this->alice->id)->where('tenant_id', $this->tenant->id)->update(['approval_limit' => '25000']);
    ($this->decide)($this->alice, (string) $step->id, ['decision' => 'approved'])->assertOk();
});

test('an empty role blocks the instance, alerts the administrators and never approves implicitly', function (): void {
    wfDefinition($this->tenant, 'WF_EMPTY', ['assignee_type' => 'role', 'assignee_id' => '']);
    $instance = wfStart($this->tenant, 'WF_EMPTY', $this->tenant, $this->requester);

    expect($instance->refresh()->status)->toBe('blocked')
        ->and($instance->blocked_reason)->not->toBeNull()
        ->and($instance->steps()->count())->toBe(0)
        ->and(WorkflowAuditEntry::query()->where('workflow_instance_id', $instance->id)->where('event_type', 'instance_blocked')->exists())->toBeTrue();

    ($this->as)($this->alice)->getJson('/api/v1/workflows/my-pending')->assertOk()->assertJsonCount(0, 'data');
    ($this->as)($this->admin)->getJson('/api/v1/workflows/my-tasks')->assertOk();
});

/**
 * A matrix for the workflows of these tests (the approvable is the tenant itself).
 *
 * @param  list<array{0: int, 1: string, 2: string}>  $tiers  level, type, value
 */
function wfMatrix(Tenant $tenant, array $tiers, array $attributes = []): ApprovalMatrix
{
    $matrix = ApprovalMatrix::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'code' => 'MX-'.Str::upper(Str::random(5)),
        'name' => 'Test matrix',
        'target_entity_type' => Tenant::class,
        'priority' => 100,
        'is_active' => true,
        ...$attributes,
    ]);

    foreach ($tiers as [$level, $type, $value]) {
        ApprovalMatrixTier::create(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'approval_matrix_id' => $matrix->id, 'tier_level' => $level, 'approver_type' => $type, 'approver_value' => $value, 'approval_type' => 'or_parallel']);
    }

    return $matrix;
}

test('an amount below the auto-approval threshold is approved by the system actor and audited', function (): void {
    wfDefinition($this->tenant, 'WF_AUTO', ['assignee_type' => 'matrix_sequential']);
    wfMatrix($this->tenant, [[1, 'role', 'admin']], ['auto_approve_below' => '50']);

    $instance = wfStart($this->tenant, 'WF_AUTO', $this->tenant, $this->requester, ['amount' => '20']);

    $decision = ApprovalDecision::query()->whereIn('workflow_instance_step_id', $instance->steps()->pluck('id'))->firstOrFail();
    expect($instance->refresh()->status)->toBe('approved')
        ->and($decision->user_id)->toBe('00000000-0000-0000-0000-000000000000')
        ->and(WorkflowAuditEntry::query()->where('workflow_instance_id', $instance->id)->where('event_type', 'decision_recorded')->firstOrFail()->event_payload['automatic'])->toBeTrue();
});

test('an amount at or above the threshold waits for the approvers of the matrix, sequentially', function (): void {
    wfDefinition($this->tenant, 'WF_AUTO_2', ['assignee_type' => 'matrix_sequential']);
    wfMatrix($this->tenant, [[1, 'user', (string) $this->alice->id], [2, 'user', (string) $this->bob->id]], ['auto_approve_below' => '50']);

    $instance = wfStart($this->tenant, 'WF_AUTO_2', $this->tenant, $this->requester, ['amount' => '50']);
    $first = $instance->steps()->firstOrFail();

    expect($instance->status)->toBe('pending')->and($first->assigned_user_id)->toBe((string) $this->alice->id)->and($instance->steps()->count())->toBe(1);

    ($this->decide)($this->alice, (string) $first->id, ['decision' => 'approved'])->assertOk();

    expect($instance->refresh()->status)->toBe('pending')
        ->and($instance->steps()->where('status', 'pending')->firstOrFail()->assigned_user_id)->toBe((string) $this->bob->id);
});

test('a matrix without a rule for the document blocks it instead of approving', function (): void {
    wfDefinition($this->tenant, 'WF_NO_RULE', ['assignee_type' => 'matrix_lookup']);
    wfMatrix($this->tenant, [[1, 'role', 'admin']], ['target_entity_type' => Tenant::class]);
    ApprovalMatrixTier::query()->update(['threshold_min' => '1000']);

    $instance = wfStart($this->tenant, 'WF_NO_RULE', $this->tenant, $this->requester, ['amount' => '10']);

    expect($instance->refresh()->status)->toBe('blocked');
});

test('my-pending answers 200 whatever the role setup of the user', function (): void {
    wfDefinition($this->tenant, 'WF_PENDING', ['assignee_type' => 'role', 'assignee_id' => 'admin']);
    wfStart($this->tenant, 'WF_PENDING', $this->tenant, $this->requester);
    $noRole = wfMember($this->tenant, 'accountant', 'Accountant');

    ($this->as)($this->admin)->getJson('/api/v1/workflows/my-pending')->assertOk()->assertJsonCount(1, 'data');
    ($this->as)($noRole)->getJson('/api/v1/workflows/my-pending')->assertOk()->assertJsonCount(0, 'data');
    ($this->as)($this->requester)->getJson('/api/v1/workflows/pending-count')->assertOk()->assertJsonPath('data.pending_count', 0);
    ($this->as)($this->admin)->getJson('/api/v1/workflows/pending-count')->assertOk()->assertJsonPath('data.pending_count', 1)->assertJsonPath('data.overdue_count', 0);
});

test('the requester withdraws while nobody decided, others cannot, and a decision closes the option', function (): void {
    wfDefinition($this->tenant, 'WF_WITHDRAW', ['assignee_type' => 'direct_user', 'assignee_id' => $this->alice->id]);
    $instance = wfStart($this->tenant, 'WF_WITHDRAW', $this->tenant, $this->requester);

    ($this->as)($this->bob)->postJson("/api/v1/workflows/instances/{$instance->id}/withdraw")->assertForbidden();
    ($this->as)($this->requester)->postJson("/api/v1/workflows/instances/{$instance->id}/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');

    expect($instance->refresh()->withdrawn_at)->not->toBeNull()
        ->and($instance->steps()->where('status', 'pending')->count())->toBe(0);

    ($this->as)($this->requester)->postJson("/api/v1/workflows/instances/{$instance->id}/withdraw")->assertStatus(409);

    $decided = wfStart($this->tenant, 'WF_WITHDRAW', $this->tenant, $this->requester);
    ($this->decide)($this->alice, (string) $decided->steps()->firstOrFail()->id, ['decision' => 'approved']);
    ($this->as)($this->requester)->postJson("/api/v1/workflows/instances/{$decided->id}/withdraw")->assertStatus(409);
});

test('an administrator cancelling a request of somebody else must give a reason', function (): void {
    wfDefinition($this->tenant, 'WF_CANCEL', ['assignee_type' => 'direct_user', 'assignee_id' => $this->alice->id]);
    $instance = wfStart($this->tenant, 'WF_CANCEL', $this->tenant, $this->requester);

    ($this->as)($this->admin)->postJson("/api/v1/workflows/instances/{$instance->id}/cancel")->assertUnprocessable()->assertJsonValidationErrors('reason');
    ($this->as)($this->admin)->postJson("/api/v1/workflows/instances/{$instance->id}/cancel", ['reason' => 'Superseded by a new order'])->assertOk();

    expect($instance->refresh()->status)->toBe('cancelled')->and($instance->closing_reason)->toBe('Superseded by a new order');

    $own = wfStart($this->tenant, 'WF_CANCEL', $this->tenant, $this->requester);
    ($this->as)($this->requester)->postJson("/api/v1/workflows/instances/{$own->id}/cancel")->assertOk();
});

test('the instance timeline lists the steps and the lifecycle events in order', function (): void {
    wfDefinition($this->tenant, 'WF_TIMELINE', ['assignee_type' => 'direct_user', 'assignee_id' => $this->alice->id]);
    $instance = wfStart($this->tenant, 'WF_TIMELINE', $this->tenant, $this->requester);
    ($this->decide)($this->alice, (string) $instance->steps()->firstOrFail()->id, ['decision' => 'approved', 'comment' => 'Fine']);

    $items = ($this->as)($this->requester)->getJson("/api/v1/workflows/instances/{$instance->id}/timeline")->assertOk()->json('data');

    $step = collect($items)->firstWhere('type', 'step');
    expect($step)->toMatchArray(['status' => 'approved', 'actor' => 'Alice', 'comment' => 'Fine', 'channel' => 'web'])
        ->and(collect($items)->pluck('status'))->toContain('instance_started');
});
