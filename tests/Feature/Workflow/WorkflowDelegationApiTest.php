<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\RecordDecisionDTO;

require_once __DIR__.'/Support/ApprovalEngineHelpers.php';

beforeEach(function (): void {
    $this->tenant = wfTenant();
    $this->requester = wfMember($this->tenant, 'member', 'Requester');
    $this->alice = wfMember($this->tenant, 'member', 'Alice');
    $this->bob = wfMember($this->tenant, 'member', 'Bob');
    $this->carol = wfMember($this->tenant, 'member', 'Carol');

    $this->delegate = function ($from, $to, array $extra = []) {
        Sanctum::actingAs($from);
        app(TenantContext::class)->clear();

        return $this->withHeader('X-Tenant-Id', (string) $this->tenant->id)->postJson('/api/v1/workflows/delegations', [
            'delegate_user_id' => (string) $to->id,
            'scope' => 'all',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addDays(5)->toDateString(),
            ...$extra,
        ]);
    };
});

afterEach(fn () => app(TenantContext::class)->clear());

test('a delegation is created with calendar dates and optional document types and amount cap', function (): void {
    ($this->delegate)($this->alice, $this->bob, ['document_types' => ['purchase_order'], 'amount_cap' => 2500])->assertSuccessful();

    $this->assertDatabaseHas('workflow_delegations', ['delegator_user_id' => $this->alice->id, 'delegate_user_id' => $this->bob->id]);
});

test('nobody delegates to themselves', function (): void {
    ($this->delegate)($this->alice, $this->alice)->assertUnprocessable()->assertJsonValidationErrors('delegate_user_id');
});

test('a circular chain is refused with 422', function (): void {
    ($this->delegate)($this->alice, $this->bob)->assertSuccessful();
    ($this->delegate)($this->bob, $this->carol)->assertSuccessful();
    ($this->delegate)($this->carol, $this->alice)->assertUnprocessable()->assertJsonValidationErrors('delegate_user_id');
});

test('an end date before the start date is refused', function (): void {
    ($this->delegate)($this->alice, $this->bob, ['starts_at' => now()->addDays(3)->toDateString(), 'ends_at' => now()->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
});

test('the delegate decides the step of the delegator through a chain of two hops', function (): void {
    wfDefinition($this->tenant, 'WF_DEL', ['assignee_type' => 'direct_user', 'assignee_id' => $this->alice->id]);
    ($this->delegate)($this->alice, $this->bob)->assertSuccessful();
    ($this->delegate)($this->bob, $this->carol)->assertSuccessful();

    $instance = wfStart($this->tenant, 'WF_DEL', wfMember($this->tenant), $this->requester);
    $step = $instance->steps()->firstOrFail();

    app(WorkflowEngineInterface::class)->recordDecision(new RecordDecisionDTO((string) $step->id, 'approved', (string) $this->carol->id));

    expect($instance->refresh()->status)->toBe('approved');
});

test('a delegation limited to other document types does not cover the step', function (): void {
    wfDefinition($this->tenant, 'WF_DEL2', ['assignee_type' => 'direct_user', 'assignee_id' => $this->alice->id]);
    ($this->delegate)($this->alice, $this->bob, ['document_types' => ['expense_report']])->assertSuccessful();

    $instance = wfStart($this->tenant, 'WF_DEL2', wfMember($this->tenant), $this->requester, ['amount' => 100]);
    $step = $instance->steps()->firstOrFail();

    expect(fn () => app(WorkflowEngineInterface::class)->recordDecision(new RecordDecisionDTO((string) $step->id, 'approved', (string) $this->bob->id)))->toThrow(Throwable::class);
});

test('a delegation is revoked by its owner', function (): void {
    $id = ($this->delegate)($this->alice, $this->bob)->assertSuccessful()->json('data.id');

    Sanctum::actingAs($this->alice);
    app(TenantContext::class)->clear();
    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id)->deleteJson("/api/v1/workflows/delegations/{$id}")->assertSuccessful();

    $this->assertDatabaseHas('workflow_delegations', ['id' => $id, 'is_active' => false]);
});
