<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\CRM\Events\OpportunityWonEvent;
use Modules\ServiceManagement\Events\CustomerSatisfactionUpdatedEvent;
use Modules\SubscriptionBilling\Events\SubscriptionCreatedEvent;
use Modules\SubscriptionBilling\Listeners\CRMSubscriptionListener;
use Modules\SubscriptionBilling\Listeners\DispatchOutboxOnSubscriptionEventListener;
use Modules\SubscriptionBilling\Listeners\ServiceManagementSubscriptionListener;
use Modules\SubscriptionBilling\Listeners\WorkflowApprovalSubscriptionListener;
use Modules\SubscriptionBilling\Models\ChurnPrediction;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->tenant);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->plan = SubscriptionPlan::factory()->create([
        'tenant_id' => $this->tenant->id,
        'billing_interval' => 'monthly',
        'base_price' => 150.00,
        'is_active' => true,
    ]);
});

test('CRM OpportunityWonEvent auto-provisions subscription via listener', function () {
    $customerId = (string) Str::uuid7();
    $event = new OpportunityWonEvent(
        opportunityId: (string) Str::uuid7(),
        accountId: $customerId,
        contactId: $customerId,
        amount: 1800.00,
        currency: 'EUR',
        salesQuoteId: null,
        actorUserId: (string) $this->user->id,
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id,
        eventId: (string) Str::uuid7()
    );

    $listener = app(CRMSubscriptionListener::class);
    $listener->handle($event);

    $sub = Subscription::where('tenant_id', $this->tenant->id)
        ->where('customer_id', $customerId)
        ->first();

    expect($sub)->not->toBeNull()
        ->and($sub->status)->toBe('active')
        ->and((float) $sub->mrr)->toBe(150.00);
});

test('Service Management low CSAT event creates churn risk prediction via listener', function () {
    $sub = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => 'active',
    ]);

    $event = new CustomerSatisfactionUpdatedEvent(
        csatSurveyId: (string) Str::uuid7(),
        score: 1, // very unsatisfied
        feedback: 'Technician was 4 hours late and did not resolve outage',
        ticketId: (string) Str::uuid7(),
        workOrderId: (string) Str::uuid7(),
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id,
        eventId: (string) Str::uuid7()
    );

    $listener = app(ServiceManagementSubscriptionListener::class);
    $listener->handle($event);

    $prediction = ChurnPrediction::where('subscription_id', $sub->id)->first();
    expect($prediction)->not->toBeNull()
        ->and($prediction->risk_tier)->toBe('high')
        ->and((float) $prediction->churn_probability_score)->toBeGreaterThan(50.0);
});

test('Workflow completed event unlocks locked subscription via listener', function () {
    $sub = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => 'pending',
        'is_locked' => true,
    ]);

    $event = new WorkflowInstanceCompletedEvent(
        instanceId: (string) Str::uuid7(),
        status: 'approved',
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id,
        definitionCode: 'HIGH_VALUE_CONTRACT_APPROVAL',
        aggregateType: 'sub_subscription',
        aggregateId: $sub->id
    );

    $listener = app(WorkflowApprovalSubscriptionListener::class);
    $listener->handle($event);

    $refreshed = $sub->fresh();
    expect($refreshed->is_locked)->toBeFalse()
        ->and($refreshed->status)->toBe('active');
});

test('outbox listener dispatches subscription domain events to transactional outbox table', function () {
    $event = new SubscriptionCreatedEvent(
        subscriptionId: (string) Str::uuid7(),
        subscriptionNumber: 'SUB-OUTBOX-001',
        customerId: (string) Str::uuid7(),
        planId: $this->plan->id,
        mrr: 150.00,
        arr: 1800.00,
        currency: 'EUR',
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id
    );

    $listener = app(DispatchOutboxOnSubscriptionEventListener::class);
    $listener->handle($event);

    $outboxEntry = TransactionalOutbox::where('aggregate_id', $event->subscriptionId)
        ->where('event_type', 'subscription.created')
        ->first();

    expect($outboxEntry)->not->toBeNull()
        ->and($outboxEntry->status)->toBe('pending')
        ->and((float) $outboxEntry->payload_json['mrr'])->toBe(150.0);
});
