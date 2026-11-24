<?php

declare(strict_types=1);

use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\CRM\Events\OpportunityWonEvent;
use Modules\ServiceManagement\Events\CustomerSatisfactionUpdatedEvent;
use Modules\ServiceManagement\Models\Ticket;
use Modules\SubscriptionBilling\Events\SubscriptionCreatedEvent;
use Modules\SubscriptionBilling\Listeners\CRMSubscriptionListener;
use Modules\SubscriptionBilling\Listeners\DispatchOutboxOnSubscriptionEventListener;
use Modules\SubscriptionBilling\Listeners\ServiceManagementSubscriptionListener;
use Modules\SubscriptionBilling\Listeners\WorkflowApprovalSubscriptionListener;
use Modules\SubscriptionBilling\Models\ChurnPrediction;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Tests\Feature\SubscriptionBilling\Support\SubscriptionScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = SubscriptionScenario::company();
    $this->customer = SubscriptionScenario::customer($this->tenant);
    $this->plan = SubscriptionScenario::plan($this->tenant, ['base_price' => '150.0000']);
});

function crossModuleOpportunity(string $contactId): OpportunityWonEvent
{
    return new OpportunityWonEvent(
        opportunityId: (string) Str::uuid7(), accountId: $contactId, contactId: $contactId, amount: 1800.00, currency: 'EUR', salesQuoteId: null,
        actorUserId: (string) Str::uuid7(), tenantId: (string) test()->tenant->id, companyId: (string) test()->tenant->id, eventId: (string) Str::uuid7()
    );
}

function crossModuleSubscription(array $overrides = []): Subscription
{
    return Subscription::create(array_merge([
        'tenant_id' => test()->tenant->id, 'subscription_number' => 'SUB-X-'.Str::random(5), 'customer_id' => test()->customer->id, 'plan_id' => test()->plan->id,
        'status' => 'active', 'start_date' => now()->toDateString(), 'current_period_start' => now()->toDateString(), 'current_period_end' => now()->addMonth()->subDay()->toDateString(), 'currency' => 'EUR',
    ], $overrides));
}

test('a won opportunity provisions a subscription only on the plan the company flagged for it', function (): void {
    app(CRMSubscriptionListener::class)->handle(crossModuleOpportunity((string) $this->customer->id));
    expect(Subscription::query()->count())->toBe(0);

    $this->plan->update(['metadata' => ['auto_provision_on_opportunity_won' => true]]);
    app(CRMSubscriptionListener::class)->handle(crossModuleOpportunity((string) Str::uuid7()));
    expect(Subscription::query()->count())->toBe(0);

    $event = crossModuleOpportunity((string) $this->customer->id);
    app(CRMSubscriptionListener::class)->handle($event);
    app(CRMSubscriptionListener::class)->handle($event);

    $subscription = Subscription::query()->where('customer_id', $this->customer->id)->sole();
    expect($subscription->status)->toBe('active')->and((string) $subscription->mrr)->toBe('150.00');
});

test('a low satisfaction score flags a churn risk on the subscriptions of the customer of the ticket only', function (): void {
    $own = crossModuleSubscription();
    $other = crossModuleSubscription(['customer_id' => SubscriptionScenario::customer($this->tenant)->id]);
    $ticket = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id]);

    app(ServiceManagementSubscriptionListener::class)->handle(new CustomerSatisfactionUpdatedEvent(
        csatSurveyId: (string) Str::uuid7(), score: 1, feedback: 'Late', ticketId: (string) $ticket->id, workOrderId: null,
        tenantId: (string) $this->tenant->id, companyId: (string) $this->tenant->id, eventId: (string) Str::uuid7()
    ));

    $prediction = ChurnPrediction::query()->where('subscription_id', $own->id)->first();
    expect($prediction)->not->toBeNull()
        ->and($prediction->risk_tier)->toBe('critical')
        ->and((float) $prediction->churn_probability_score)->toBe(100.0)
        ->and(ChurnPrediction::query()->where('subscription_id', $other->id)->exists())->toBeFalse();
});

test('an approved workflow activates a pending subscription, a rejected one cancels it', function (string $status, string $expected): void {
    $subscription = crossModuleSubscription(['status' => 'pending', 'is_locked' => true]);

    app(WorkflowApprovalSubscriptionListener::class)->handle(new WorkflowInstanceCompletedEvent(
        instanceId: (string) Str::uuid7(), status: $status, tenantId: (string) $this->tenant->id, companyId: (string) $this->tenant->id,
        definitionCode: 'HIGH_VALUE_CONTRACT_APPROVAL', aggregateType: 'sub_subscription', aggregateId: $subscription->id
    ));

    expect($subscription->fresh()->status)->toBe($expected)->and($subscription->fresh()->is_locked)->toBeFalse();
})->with([['approved', 'active'], ['rejected', 'cancelled']]);

test('the outbox listener records each subscription event once', function (): void {
    $event = new SubscriptionCreatedEvent(
        subscriptionId: (string) Str::uuid7(), subscriptionNumber: 'SUB-OUTBOX-001', customerId: (string) Str::uuid7(), planId: $this->plan->id,
        mrr: 150.00, arr: 1800.00, currency: 'EUR', tenantId: (string) $this->tenant->id, companyId: (string) $this->tenant->id
    );

    app(DispatchOutboxOnSubscriptionEventListener::class)->handle($event);
    app(DispatchOutboxOnSubscriptionEventListener::class)->handle($event);

    $entries = TransactionalOutbox::where('aggregate_id', $event->subscriptionId)->where('event_type', 'subscription.created')->get();
    expect($entries)->toHaveCount(1)
        ->and($entries->first()->status)->toBe('pending')
        ->and((float) $entries->first()->payload_json['mrr'])->toBe(150.0);
});
