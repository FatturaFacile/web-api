<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Workflow\DTOs\RecordDecisionDTO;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Models\ApprovalDecision;
use Modules\Workflow\Models\BusinessCalendar;
use Modules\Workflow\Models\BusinessCalendarHoliday;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowDelegation;
use Modules\Workflow\Models\WorkflowDeliveryAttempt;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;
use Modules\Workflow\Models\WorkflowNode;
use Modules\Workflow\Models\WorkflowTransition;
use Modules\Workflow\Models\WorkflowWebhookSubscription;
use Modules\Workflow\Services\BusinessCalendarCalculationService;
use Modules\Workflow\Services\ExternalActionTokenService;
use Modules\Workflow\Services\VotingThresholdEvaluationService;
use Modules\Workflow\Services\WorkflowAuditLedgerService;
use Modules\Workflow\Services\WorkflowEngineService;
use Modules\Workflow\Services\WorkflowWebhookDispatcherService;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Acme Workflow Enterprise',
        'legal_name' => 'Acme Workflow Enterprise S.r.l.',
        'vat_number' => 'IT99999999999',
        'status' => 'active',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);

    $this->user1 = User::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'name' => 'Approver Alpha',
        'email' => 'alpha_'.Str::random(8).'@acme.test',
        'password' => bcrypt('secret'),
    ]);

    $this->user2 = User::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'name' => 'Approver Beta',
        'email' => 'beta_'.Str::random(8).'@acme.test',
        'password' => bcrypt('secret'),
    ]);

    // Setup a standard workflow definition: Start -> Approval Node -> End Node
    $this->definition = WorkflowDefinition::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'code' => 'WF_INVOICE_APPROVAL',
        'name' => 'Standard Invoice Approval',
        'target_entity_type' => Tenant::class,
        'version' => 1,
        'status' => 'published',
        'is_active' => true,
    ]);

    $this->startNode = WorkflowNode::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'workflow_definition_id' => $this->definition->id,
        'node_key' => 'start',
        'name' => 'Start Process',
        'node_type' => 'start',
    ]);

    $this->approvalNode = WorkflowNode::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'workflow_definition_id' => $this->definition->id,
        'node_key' => 'manager_approval',
        'name' => 'Manager Approval Step',
        'node_type' => 'approval',
        'approval_type' => 'single',
        'assignee_type' => 'direct_user',
        'assignee_id' => $this->user1->id,
        'timeout_hours' => 24,
    ]);

    $this->endNode = WorkflowNode::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'workflow_definition_id' => $this->definition->id,
        'node_key' => 'end',
        'name' => 'Process Complete',
        'node_type' => 'end',
    ]);

    // Start -> Manager Approval
    WorkflowTransition::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'workflow_definition_id' => $this->definition->id,
        'from_node_id' => $this->startNode->id,
        'to_node_id' => $this->approvalNode->id,
        'action_trigger' => 'start',
    ]);

    // Manager Approval -> End (on approve)
    WorkflowTransition::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'workflow_definition_id' => $this->definition->id,
        'from_node_id' => $this->approvalNode->id,
        'to_node_id' => $this->endNode->id,
        'action_trigger' => 'approved',
    ]);
});

test('workflow starts and transitions to initial pending approval step', function () {
    $engine = app(WorkflowEngineService::class);

    $instance = $engine->start(new StartWorkflowDTO(
        workflowCode: 'WF_INVOICE_APPROVAL',
        approvable: $this->tenant,
        initiatorUserId: $this->user1->id,
        tenantId: $this->tenant->id
    ));

    expect($instance)->toBeInstanceOf(WorkflowInstance::class)
        ->and($instance->status)->toBe('pending')
        ->and($instance->current_node_id)->toBe($this->approvalNode->id);

    $steps = $instance->steps()->get();
    expect($steps)->toHaveCount(1)
        ->and($steps->first()->status)->toBe('pending')
        ->and($steps->first()->assigned_user_id)->toBe($this->user1->id);
});

test('concurrency protection prevents duplicate workflow creation on the same document', function () {
    $engine = app(WorkflowEngineService::class);

    $engine->start(new StartWorkflowDTO(
        workflowCode: 'WF_INVOICE_APPROVAL',
        approvable: $this->tenant,
        initiatorUserId: $this->user1->id,
        tenantId: $this->tenant->id
    ));

    expect(function () use ($engine) {
        $engine->start(new StartWorkflowDTO(
            workflowCode: 'WF_INVOICE_APPROVAL',
            approvable: $this->tenant,
            initiatorUserId: $this->user1->id,
            tenantId: $this->tenant->id
        ));
    })->toThrow(InvalidArgumentException::class, 'already in progress for this document');
});

test('sequential workflow completes when approval decision is recorded', function () {
    $engine = app(WorkflowEngineService::class);

    $instance = $engine->start(new StartWorkflowDTO(
        workflowCode: 'WF_INVOICE_APPROVAL',
        approvable: $this->tenant,
        initiatorUserId: $this->user1->id,
        tenantId: $this->tenant->id
    ));

    $step = $instance->steps()->first();

    $engine->recordDecision(new RecordDecisionDTO(
        workflowInstanceStepId: $step->id,
        decision: 'approved',
        userId: $this->user1->id,
        comment: 'LGTM, approved for payment.'
    ));

    $step->refresh();
    $instance->refresh();

    expect($step->status)->toBe('approved')
        ->and($instance->status)->toBe('approved')
        ->and($instance->completed_at)->not->toBeNull();
});

test('workflow transitions to rejected status on rejection decision', function () {
    $engine = app(WorkflowEngineService::class);

    $instance = $engine->start(new StartWorkflowDTO(
        workflowCode: 'WF_INVOICE_APPROVAL',
        approvable: $this->tenant,
        initiatorUserId: $this->user1->id,
        tenantId: $this->tenant->id
    ));

    $step = $instance->steps()->first();

    $engine->recordDecision(new RecordDecisionDTO(
        workflowInstanceStepId: $step->id,
        decision: 'rejected',
        userId: $this->user1->id,
        comment: 'Invoice totals do not reconcile.'
    ));

    $step->refresh();
    $instance->refresh();

    expect($step->status)->toBe('rejected')
        ->and($instance->status)->toBe('rejected')
        ->and($instance->completed_at)->not->toBeNull();
});

test('unauthorized user cannot record decision without valid delegation', function () {
    $engine = app(WorkflowEngineService::class);

    $instance = $engine->start(new StartWorkflowDTO(
        workflowCode: 'WF_INVOICE_APPROVAL',
        approvable: $this->tenant,
        initiatorUserId: $this->user1->id,
        tenantId: $this->tenant->id
    ));

    $step = $instance->steps()->first();

    // User2 is not assigned to step and has no delegation from User1
    expect(function () use ($engine, $step) {
        $engine->recordDecision(new RecordDecisionDTO(
            workflowInstanceStepId: $step->id,
            decision: 'approved',
            userId: $this->user2->id,
            comment: 'Unauthorized spoofing attempt'
        ));
    })->toThrow(AuthorizationException::class);
});

test('delegation allows delegate to record decision on behalf of delegator', function () {
    $engine = app(WorkflowEngineService::class);

    // Create active delegation: user1 delegates to user2
    WorkflowDelegation::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'delegator_user_id' => $this->user1->id,
        'delegate_user_id' => $this->user2->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDays(5),
        'is_active' => true,
        'reason' => 'Annual leave delegation',
    ]);

    $instance = $engine->start(new StartWorkflowDTO(
        workflowCode: 'WF_INVOICE_APPROVAL',
        approvable: $this->tenant,
        initiatorUserId: $this->user1->id,
        tenantId: $this->tenant->id
    ));

    $step = $instance->steps()->first();

    $engine->recordDecision(new RecordDecisionDTO(
        workflowInstanceStepId: $step->id,
        decision: 'approved',
        userId: $this->user2->id,
        comment: 'Approved via valid delegation.'
    ));

    $decision = ApprovalDecision::where('workflow_instance_step_id', $step->id)->first();

    expect($decision)->not->toBeNull()
        ->and($decision->user_id)->toBe($this->user2->id)
        ->and($decision->effective_user_id)->toBe($this->user2->id);

    $instance->refresh();
    expect($instance->status)->toBe('approved');
});

test('parallel voting evaluates majority and supermajority thresholds accurately', function () {
    $votingService = app(VotingThresholdEvaluationService::class);

    $node = new WorkflowNode([
        'approval_type' => 'majority',
        'minimum_votes_required' => 2,
    ]);

    $step1 = new WorkflowInstanceStep(['status' => 'approved']);
    $step2 = new WorkflowInstanceStep(['status' => 'pending']);
    $step3 = new WorkflowInstanceStep(['status' => 'approved']);

    // 2 out of 3 approved = 66.6% > 50% majority
    $steps = collect([$step1, $step2, $step3]);

    $result = $votingService->evaluate($node, $steps);

    expect($result['completed'])->toBeTrue()
        ->and($result['outcome'])->toBe('approved');
});

test('supermajority threshold requires specified percentage and minimum votes', function () {
    $votingService = app(VotingThresholdEvaluationService::class);

    $node = new WorkflowNode([
        'approval_type' => 'supermajority',
        'voting_threshold_percentage' => 75.0,
        'minimum_votes_required' => 3,
    ]);

    // 2 approved, 1 rejected, 1 pending (2/4 = 50% < 75%)
    $step1 = new WorkflowInstanceStep(['status' => 'approved']);
    $step2 = new WorkflowInstanceStep(['status' => 'approved']);
    $step3 = new WorkflowInstanceStep(['status' => 'rejected']);
    $step4 = new WorkflowInstanceStep(['status' => 'pending']);

    $steps = collect([$step1, $step2, $step3, $step4]);
    $result = $votingService->evaluate($node, $steps);

    expect($result['completed'])->toBeFalse();

    // Now 3 approved out of 4 (75% and min votes 3 reached)
    $step4->status = 'approved';
    $result2 = $votingService->evaluate($node, $steps);

    expect($result2['completed'])->toBeTrue()
        ->and($result2['outcome'])->toBe('approved');
});

test('external action token service generates single use tokens and prevents replay attacks', function () {
    $tokenService = app(ExternalActionTokenService::class);

    $instance = app(WorkflowEngineService::class)->start(new StartWorkflowDTO(
        workflowCode: 'WF_INVOICE_APPROVAL',
        approvable: $this->tenant,
        initiatorUserId: $this->user1->id,
        tenantId: $this->tenant->id
    ));

    $step = $instance->steps()->first();

    $signedUrl = $tokenService->generateActionToken($step, $this->user1, 'approve');

    parse_str(parse_url($signedUrl, PHP_URL_QUERY), $queryParams);
    $rawToken = $queryParams['token'];

    // 1. First consumption succeeds
    $consumedToken = $tokenService->verifyAndConsumeToken($rawToken, 'approve');
    expect($consumedToken->used_at)->not->toBeNull()
        ->and($consumedToken->workflow_instance_step_id)->toBe($step->id);

    // 2. Second consumption (replay attack) fails
    expect(function () use ($tokenService, $rawToken) {
        $tokenService->verifyAndConsumeToken($rawToken, 'approve');
    })->toThrow(InvalidArgumentException::class, 'already been consumed');
});

test('business calendar calculator excludes weekends and bank holidays', function () {
    $calculator = app(BusinessCalendarCalculationService::class);

    $calendar = BusinessCalendar::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'name' => 'Italian Standard Office Calendar',
        'timezone' => 'Europe/Rome',
        'work_hours_start' => '09:00:00',
        'work_hours_end' => '18:00:00',
        'work_monday' => true,
        'work_tuesday' => true,
        'work_wednesday' => true,
        'work_thursday' => true,
        'work_friday' => true,
        'work_saturday' => false,
        'work_sunday' => false,
    ]);

    // Add holiday on next Monday
    $start = Carbon::parse('2026-11-06 10:00:00', 'Europe/Rome'); // A Friday
    $monday = Carbon::parse('2026-11-09');

    BusinessCalendarHoliday::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'business_calendar_id' => $calendar->id,
        'holiday_date' => $monday->toDateString(),
        'name' => 'Patron Saint Feast Day',
    ]);

    // 16 business hours from Friday 10:00:
    // Friday: 10:00 to 18:00 = 8h.
    // Saturday & Sunday: skipped.
    // Monday: Holiday skipped.
    // Tuesday: 09:00 to 17:00 = 8h. Deadline = Tuesday 17:00!
    $deadline = $calculator->calculateDeadline($start, 16, $calendar)->setTimezone('Europe/Rome');

    expect($deadline->isTuesday())->toBeTrue()
        ->and($deadline->format('Y-m-d H:i'))->toBe('2026-11-10 17:00');
});

test('webhook dispatcher signs outbound payload with HMAC-SHA256 and records delivery attempt', function () {
    Http::fake([
        'https://external.service/webhook' => Http::response(['received' => true], 200),
    ]);

    $dispatcher = app(WorkflowWebhookDispatcherService::class);

    $sub = WorkflowWebhookSubscription::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'name' => 'ERP Notification Sink',
        'target_url' => 'https://external.service/webhook',
        'secret_key' => 'super_secret_webhook_key_xyz',
        'subscribed_events' => ['workflow.completed'],
        'is_active' => true,
    ]);

    $dispatcher->dispatch(
        tenantId: $this->tenant->id,
        eventName: 'workflow.completed',
        payload: ['instance_id' => 'test-uuid-123', 'status' => 'approved']
    );

    $attempt = WorkflowDeliveryAttempt::where('subscription_id', $sub->id)->first();
    expect($attempt)->not->toBeNull();

    $success = $dispatcher->deliver($attempt);

    expect($success)->toBeTrue();
    $attempt->refresh();
    expect($attempt->status)->toBe('successful')
        ->and($attempt->http_status)->toBe(200);

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-FatturaFacile-Signature')
            && str_starts_with($request->header('X-FatturaFacile-Signature')[0], 'sha256=');
    });
});

test('audit ledger maintains unbreakable cryptographic hash chain', function () {
    $auditService = app(WorkflowAuditLedgerService::class);

    $instance = WorkflowInstance::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'workflow_definition_id' => $this->definition->id,
        'workflow_definition_version' => 1,
        'approvable_type' => Tenant::class,
        'approvable_id' => $this->tenant->id,
        'status' => 'pending',
    ]);

    // Record Event 1: started
    $entry1 = $auditService->record(
        instance: $instance,
        eventType: 'instance_started',
        payload: ['version' => 1],
        actorUserId: $this->user1->id
    );

    // Record Event 2: step assigned
    $entry2 = $auditService->record(
        instance: $instance,
        eventType: 'step_assigned',
        payload: ['user' => $this->user1->id],
        actorUserId: $this->user1->id
    );

    // Record Event 3: decision recorded
    $entry3 = $auditService->record(
        instance: $instance,
        eventType: 'decision_recorded',
        payload: ['decision' => 'approved'],
        actorUserId: $this->user1->id
    );

    $secret = config('app.key', 'workflow_audit_salt');

    // Verify Entry 1 HMAC based on GENESIS
    $payload1 = json_encode(['version' => 1], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $expectedHmac1 = hash_hmac('sha256', 'GENESIS|'.$instance->id.'|instance_started|'.$payload1, $secret);
    expect($entry1->hmac_signature)->toBe($expectedHmac1);

    // Verify Entry 2 HMAC incorporates Entry 1's signature
    $payload2 = json_encode(['user' => $this->user1->id], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $expectedHmac2 = hash_hmac('sha256', $entry1->hmac_signature.'|'.$instance->id.'|step_assigned|'.$payload2, $secret);
    expect($entry2->hmac_signature)->toBe($expectedHmac2);

    // Verify Entry 3 HMAC incorporates Entry 2's signature
    $payload3 = json_encode(['decision' => 'approved'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $expectedHmac3 = hash_hmac('sha256', $entry2->hmac_signature.'|'.$instance->id.'|decision_recorded|'.$payload3, $secret);
    expect($entry3->hmac_signature)->toBe($expectedHmac3);
});

test('tenant isolation guarantees complete cross-tenant boundary security', function () {
    $otherTenant = Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Foreign Corp',
        'legal_name' => 'Foreign Corp S.A.',
        'vat_number' => 'IT11111111111',
        'status' => 'active',
    ]);

    $foreignUser = User::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $otherTenant->id,
        'name' => 'Foreign User',
        'email' => 'foreign_'.Str::random(8).'@foreign.test',
        'password' => bcrypt('secret'),
    ]);

    // Start instance in tenant 1
    $instance = app(WorkflowEngineService::class)->start(new StartWorkflowDTO(
        workflowCode: 'WF_INVOICE_APPROVAL',
        approvable: $this->tenant,
        initiatorUserId: $this->user1->id,
        tenantId: $this->tenant->id
    ));

    $step = $instance->steps()->first();

    // Foreign user in tenant 2 attempts to decide on tenant 1 step
    expect(function () use ($step, $foreignUser) {
        app(WorkflowEngineService::class)->recordDecision(new RecordDecisionDTO(
            workflowInstanceStepId: $step->id,
            decision: 'approved',
            userId: $foreignUser->id,
            comment: 'Cross-tenant breach attempt'
        ));
    })->toThrow(AuthorizationException::class);
});
