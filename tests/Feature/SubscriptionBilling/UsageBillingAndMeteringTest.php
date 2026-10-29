<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Contracts\UsageBillingServiceInterface;
use Modules\SubscriptionBilling\DTOs\RecordUsageDTO;
use Modules\SubscriptionBilling\Models\PlanFeature;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;
use Modules\SubscriptionBilling\Models\UsageEvent;
use Modules\SubscriptionBilling\Models\UsageMetric;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->tenant);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->plan = SubscriptionPlan::factory()->create([
        'tenant_id' => $this->tenant->id,
        'billing_interval' => 'monthly',
        'base_price' => 50.00,
    ]);

    $this->feature = PlanFeature::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'feature_code' => 'API_CALLS',
        'included_quantity' => 1000.0,
        'overage_unit_price' => 0.05,
    ]);

    $this->metric = UsageMetric::factory()->create([
        'tenant_id' => $this->tenant->id,
        'metric_code' => 'API_CALLS',
        'aggregation_type' => 'sum',
    ]);

    $this->subscription = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => 'active',
    ]);

    $this->item = SubscriptionItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $this->subscription->id,
        'item_type' => 'usage_meter',
        'metric_id' => $this->metric->id,
        'description' => 'API Calls Meter',
    ]);

    $this->usageService = app(UsageBillingServiceInterface::class);
});

test('usage billing records events idempotently and avoids duplicates', function () {
    $idempotencyKey = 'IDEMP-'.Str::random(16);

    $dto = new RecordUsageDTO(
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id,
        subscriptionId: $this->subscription->id,
        subscriptionItemId: $this->item->id,
        metricCode: 'API_CALLS',
        quantity: 250.0,
        idempotencyKey: $idempotencyKey
    );

    $event1 = $this->usageService->recordUsage($dto);
    $event2 = $this->usageService->recordUsage($dto);

    expect($event1->id)->toBe($event2->id)
        ->and(UsageEvent::where('idempotency_key', $idempotencyKey)->count())->toBe(1);
});

test('usage billing aggregates consumption, applies allowance, and rates overage', function () {
    $periodStart = now()->startOfMonth();
    $periodEnd = now()->endOfMonth();

    // Ingest 3 batches of usage = 500 + 700 + 400 = 1600 units
    // Included allowance = 1000 units => Overage = 600 units
    // Rate = 0.05 EUR => Billable amount = 30.00 EUR
    foreach ([500.0, 700.0, 400.0] as $qty) {
        $this->usageService->recordUsage(new RecordUsageDTO(
            tenantId: $this->tenant->id,
            companyId: $this->tenant->id,
            subscriptionId: $this->subscription->id,
            subscriptionItemId: $this->item->id,
            metricCode: 'API_CALLS',
            quantity: $qty,
            eventTimestamp: now()->toDateString()
        ));
    }

    $aggregation = $this->usageService->aggregateUsage($this->item->id, $periodStart, $periodEnd);

    expect((float) $aggregation->total_consumed)->toBe(1600.0)
        ->and((float) $aggregation->included_allowance)->toBe(1000.0)
        ->and((float) $aggregation->billable_overage)->toBe(600.0)
        ->and((float) $aggregation->billable_amount)->toBe(30.00);

    // Verify all processed events are marked as processed
    $unprocessed = UsageEvent::where('subscription_item_id', $this->item->id)
        ->where('is_processed', false)
        ->count();

    expect($unprocessed)->toBe(0);
});
