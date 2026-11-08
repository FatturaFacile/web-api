<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SubscriptionBilling\Contracts\UsageBillingServiceInterface;
use Modules\SubscriptionBilling\DTOs\RecordUsageDTO;
use Modules\SubscriptionBilling\Models\PlanFeature;
use Modules\SubscriptionBilling\Models\PricingTier;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Modules\SubscriptionBilling\Models\UsageEvent;
use Modules\SubscriptionBilling\Models\UsageMetric;
use Tests\Feature\SubscriptionBilling\Support\SubscriptionScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-03-15 09:00:00');
    $this->tenant = SubscriptionScenario::company();
    $this->metric = UsageMetric::create(['tenant_id' => $this->tenant->id, 'metric_code' => 'STORAGE_GB', 'name' => 'Storage', 'aggregation_type' => 'max']);
    $this->plan = SubscriptionScenario::plan($this->tenant, ['pricing_model' => 'metered', 'base_price' => '0']);
    $this->subscription = Subscription::create([
        'tenant_id' => $this->tenant->id, 'subscription_number' => 'SUB-USG-1', 'customer_id' => SubscriptionScenario::customer($this->tenant)->id, 'plan_id' => $this->plan->id,
        'status' => 'active', 'start_date' => '2026-03-01', 'current_period_start' => '2026-04-01', 'current_period_end' => '2026-04-30', 'currency' => 'EUR',
    ]);
    $this->item = SubscriptionItem::create(['tenant_id' => $this->tenant->id, 'subscription_id' => $this->subscription->id, 'item_type' => 'usage_meter', 'description' => 'Storage', 'quantity' => '0', 'unit_price' => '0.5', 'metric_id' => $this->metric->id, 'price_factor' => '1']);
    $this->usage = app(UsageBillingServiceInterface::class);
});

function usageRecord(string $quantity, string $key, string $at): UsageEvent
{
    return test()->usage->recordUsage(new RecordUsageDTO((string) test()->tenant->id, (string) test()->tenant->id, test()->subscription->id, test()->item->id, 'STORAGE_GB', $quantity, $at, $key));
}

test('usage records are idempotent on their key', function (): void {
    $first = usageRecord('10', 'k-1', '2026-03-02 10:00:00');
    $again = usageRecord('99', 'k-1', '2026-03-03 10:00:00');

    expect($again->id)->toBe($first->id)
        ->and((string) $first->quantity)->toBe('10.0000')
        ->and(UsageEvent::query()->count())->toBe(1);
});

test('aggregation follows the metric, applies the allowance and rates the overage; a billed aggregation never changes', function (): void {
    PlanFeature::create(['tenant_id' => $this->tenant->id, 'plan_id' => $this->plan->id, 'feature_code' => 'STORAGE_GB', 'name' => 'Storage', 'included_quantity' => '50', 'overage_unit_price' => '0.2500', 'is_active' => true]);
    usageRecord('40', 'k-1', '2026-03-02 10:00:00');
    usageRecord('180', 'k-2', '2026-03-10 10:00:00');
    usageRecord('120', 'k-3', '2026-03-20 10:00:00');

    $aggregation = $this->usage->aggregateUsage($this->item->id, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

    // max = 180, 50 included, 130 x 0.25.
    expect((string) $aggregation->total_consumed)->toBe('180.0000')
        ->and((string) $aggregation->billable_overage)->toBe('130.0000')
        ->and($this->usage->rateUsage($aggregation))->toBe('32.50')
        ->and(UsageEvent::query()->where('is_processed', true)->count())->toBe(3);

    $aggregation->update(['is_billed' => true]);
    usageRecord('500', 'k-4', '2026-03-21 10:00:00');
    expect((string) $this->usage->aggregateUsage($this->item->id, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'))->billable_amount)->toBe('32.50');
});

test('a metered plan with tiers rates the usage through its graduated tiers', function (): void {
    PricingTier::create(['tenant_id' => $this->tenant->id, 'plan_id' => $this->plan->id, 'tier_number' => 1, 'from_quantity' => '0', 'to_quantity' => '100', 'unit_price' => '1', 'flat_fee' => '0']);
    PricingTier::create(['tenant_id' => $this->tenant->id, 'plan_id' => $this->plan->id, 'tier_number' => 2, 'from_quantity' => '100', 'to_quantity' => null, 'unit_price' => '0.5', 'flat_fee' => '0']);
    $this->metric->update(['aggregation_type' => 'sum']);
    usageRecord('150', 'k-1', '2026-03-02 10:00:00');

    $aggregation = $this->usage->aggregateUsage($this->item->id, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

    expect((string) $aggregation->billable_amount)->toBe('125.00');
});
