<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;
use Modules\SubscriptionBilling\Models\PlanFeature;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Modules\SubscriptionBilling\Models\UsageAggregation;
use Modules\SubscriptionBilling\Models\UsageEvent;
use Modules\SubscriptionBilling\Models\UsageMetric;
use Tests\Feature\SubscriptionBilling\Support\SubscriptionScenario;

uses(RefreshDatabase::class);

/**
 * Plans (price models, tiers, features) and metered usage billed in arrears.
 */
beforeEach(function (): void {
    $this->travelTo('2026-03-15 09:00:00');
    $this->tenant = SubscriptionScenario::company();
    $this->user = SubscriptionScenario::actingAs($this->tenant);
    $this->customer = SubscriptionScenario::customer($this->tenant);
});

test('plans are created with their tiers and features, listed, shown and updated', function (): void {
    $id = $this->postJson('/api/v1/subscriptions/plans', [
        'plan_code' => 'TEAM', 'name' => 'Team', 'billing_interval' => 'monthly', 'pricing_model' => 'tiered', 'base_price' => '0', 'tax_rate' => '22',
        'tiers' => [['from_quantity' => 0, 'to_quantity' => 10, 'unit_price' => '8'], ['from_quantity' => 10, 'unit_price' => '6']],
        'features' => [['feature_code' => 'API_CALLS', 'name' => 'API calls', 'included_quantity' => 1000, 'overage_unit_price' => '0.01']],
    ])->assertCreated()->assertJsonPath('data.tiers.1.from_quantity', '10.0000')->assertJsonPath('data.features.0.feature_code', 'API_CALLS')->json('data.id');

    $this->getJson('/api/v1/subscriptions/plans')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/subscriptions/plans?is_active=0')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson("/api/v1/subscriptions/plans/{$id}")->assertOk()->assertJsonPath('data.pricing_model', 'tiered');
    $this->putJson("/api/v1/subscriptions/plans/{$id}", ['name' => 'Team 2026', 'is_active' => false, 'renewal_price_increase_percent' => 3])->assertOk()
        ->assertJsonPath('data.name', 'Team 2026')->assertJsonPath('data.is_active', false)->assertJsonPath('data.renewal_price_increase_percent', '3.0000');
    $this->putJson("/api/v1/subscriptions/plans/{$id}", ['tax_rate' => 150])->assertUnprocessable()->assertJsonValidationErrors('tax_rate');
});

test('plan creation validates the tier ladder, the code and the accounts', function (): void {
    SubscriptionScenario::plan($this->tenant, ['plan_code' => 'TAKEN']);
    $base = ['name' => 'X', 'billing_interval' => 'monthly', 'base_price' => '1'];

    $this->postJson('/api/v1/subscriptions/plans', [...$base, 'plan_code' => 'TAKEN'])->assertUnprocessable()->assertJsonValidationErrors('plan_code');
    $this->postJson('/api/v1/subscriptions/plans', [...$base, 'plan_code' => 'A', 'pricing_model' => 'volume'])->assertUnprocessable()->assertJsonValidationErrors('tiers');
    $this->postJson('/api/v1/subscriptions/plans', [...$base, 'plan_code' => 'B', 'pricing_model' => 'tiered', 'tiers' => [['from_quantity' => 0, 'to_quantity' => 10, 'unit_price' => 1], ['from_quantity' => 12, 'unit_price' => 1]]])->assertUnprocessable()->assertJsonValidationErrors('tiers');
    $this->postJson('/api/v1/subscriptions/plans', [...$base, 'plan_code' => 'C', 'billing_interval' => 'weekly', 'revenue_account_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['billing_interval', 'revenue_account_id']);
});

test('usage is recorded idempotently and validated against the metered item', function (): void {
    $metric = UsageMetric::create(['tenant_id' => $this->tenant->id, 'metric_code' => 'API_CALLS', 'name' => 'API calls', 'aggregation_type' => 'sum']);
    $plan = SubscriptionScenario::plan($this->tenant, ['pricing_model' => 'hybrid', 'base_price' => '50.0000']);
    PlanFeature::create(['tenant_id' => $this->tenant->id, 'plan_id' => $plan->id, 'feature_code' => 'API_CALLS', 'name' => 'API calls', 'metric_type' => 'metered_quota', 'included_quantity' => '1000', 'overage_unit_price' => '0.0100', 'is_active' => true]);
    $id = $this->postJson('/api/v1/sub/contracts', ['customer_id' => $this->customer->id, 'plan_id' => $plan->id, 'start_date' => '2026-02-15'])->assertCreated()->json('data.id');
    $meter = SubscriptionItem::query()->where('subscription_id', $id)->where('metric_id', $metric->id)->firstOrFail();
    $planItem = SubscriptionItem::query()->where('subscription_id', $id)->where('item_type', 'plan')->firstOrFail();

    $payload = ['subscription_item_id' => $meter->id, 'metric_code' => 'API_CALLS', 'quantity' => 1800, 'event_timestamp' => '2026-02-20 10:00:00', 'idempotency_key' => 'evt-1'];
    $first = $this->postJson('/api/v1/subscriptions/usage', $payload)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/subscriptions/usage', $payload)->assertCreated()->assertJsonPath('data.id', $first);
    $this->postJson('/api/v1/subscriptions/usage', [...$payload, 'idempotency_key' => 'evt-2', 'quantity' => 700, 'event_timestamp' => '2026-03-01 10:00:00'])->assertCreated();

    $this->postJson('/api/v1/subscriptions/usage', [...$payload, 'idempotency_key' => 'evt-3', 'metric_code' => 'STORAGE'])->assertUnprocessable()->assertJsonValidationErrors('metric_code');
    $this->postJson('/api/v1/subscriptions/usage', [...$payload, 'idempotency_key' => 'evt-4', 'subscription_item_id' => $planItem->id])->assertUnprocessable()->assertJsonValidationErrors('subscription_item_id');
    $this->postJson('/api/v1/subscriptions/usage', [...$payload, 'quantity' => -1])->assertUnprocessable()->assertJsonValidationErrors('quantity');

    expect(UsageEvent::query()->count())->toBe(2);
    $this->getJson("/api/v1/subscriptions/usage?subscription_id={$id}")->assertOk()->assertJsonPath('meta.total', 2);
});

test('metered usage of the previous period is rated and billed in arrears with the next invoice', function (): void {
    $metric = UsageMetric::create(['tenant_id' => $this->tenant->id, 'metric_code' => 'API_CALLS', 'name' => 'API calls', 'aggregation_type' => 'sum']);
    $plan = SubscriptionScenario::plan($this->tenant, ['pricing_model' => 'hybrid', 'base_price' => '50.0000']);
    PlanFeature::create(['tenant_id' => $this->tenant->id, 'plan_id' => $plan->id, 'feature_code' => 'API_CALLS', 'name' => 'API calls', 'metric_type' => 'metered_quota', 'included_quantity' => '1000', 'overage_unit_price' => '0.0100', 'is_active' => true]);
    $id = $this->postJson('/api/v1/sub/contracts', ['customer_id' => $this->customer->id, 'plan_id' => $plan->id, 'start_date' => '2026-02-15'])->json('data.id');
    $meter = SubscriptionItem::query()->where('subscription_id', $id)->where('metric_id', $metric->id)->firstOrFail();

    $this->travelTo('2026-02-15 09:00:00');
    $this->postJson("/api/v1/sub/contracts/{$id}/bill")->assertOk()->assertJsonCount(1, 'data');
    $this->travelTo('2026-03-15 09:00:00');
    $this->postJson('/api/v1/subscriptions/usage', ['subscription_item_id' => $meter->id, 'metric_code' => 'API_CALLS', 'quantity' => 1800, 'event_timestamp' => '2026-02-20 10:00:00'])->assertCreated();
    $this->postJson('/api/v1/subscriptions/usage', ['subscription_item_id' => $meter->id, 'metric_code' => 'API_CALLS', 'quantity' => 700, 'event_timestamp' => '2026-03-01 10:00:00'])->assertCreated();

    $this->postJson("/api/v1/sub/contracts/{$id}/bill")->assertOk()->assertJsonCount(1, 'data');

    // 2500 used, 1000 included: 1500 x 0.01 = 15.00 on top of the 50.00 of March.
    $march = InvoiceSchedule::query()->where('subscription_id', $id)->whereDate('service_period_start', '2026-03-15')->firstOrFail();
    $aggregation = UsageAggregation::query()->where('subscription_item_id', $meter->id)->firstOrFail();
    expect((string) $march->net_amount)->toBe('65.00')
        ->and($aggregation->is_billed)->toBeTrue()
        ->and($aggregation->invoice_schedule_id)->toBe($march->id)
        ->and((string) $aggregation->billable_amount)->toBe('15.00');
});
