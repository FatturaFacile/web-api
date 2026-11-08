<?php

declare(strict_types=1);

use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Contracts\RenewalServiceInterface;
use Modules\SubscriptionBilling\Contracts\SubscriptionForecastingServiceInterface;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Tests\Feature\SubscriptionBilling\Support\SubscriptionScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-03-15 09:00:00');
    $this->tenant = SubscriptionScenario::company();
    $this->plan = SubscriptionScenario::plan($this->tenant, ['billing_interval' => 'annual', 'base_price' => '2400.0000']);
    $this->renewalService = app(RenewalServiceInterface::class);
    $this->forecastingService = app(SubscriptionForecastingServiceInterface::class);
});

function treasurySubscription(array $overrides = []): Subscription
{
    $subscription = Subscription::create(array_merge([
        'tenant_id' => test()->tenant->id, 'subscription_number' => 'SUB-T-'.Str::random(5), 'customer_id' => SubscriptionScenario::customer(test()->tenant)->id,
        'plan_id' => test()->plan->id, 'status' => 'active', 'start_date' => '2025-03-10', 'billing_anchor_date' => '2025-03-10', 'term_end_date' => '2026-03-09',
        'current_period_start' => '2026-03-10', 'current_period_end' => '2027-03-09', 'contract_term_months' => 12, 'auto_renew' => true, 'currency' => 'EUR', 'mrr' => '200.00', 'arr' => '2400.00',
    ], $overrides));
    SubscriptionItem::create(['tenant_id' => test()->tenant->id, 'subscription_id' => $subscription->id, 'plan_id' => test()->plan->id, 'item_type' => 'plan', 'description' => 'Annual', 'quantity' => '1', 'unit_price' => '2400', 'price_factor' => '1']);

    return $subscription;
}

test('a term that ended renews by itself for another term and is versioned', function (): void {
    $subscription = treasurySubscription();

    $result = $this->renewalService->processExpiringSubscriptions((string) $this->tenant->id, 30);

    expect($result['auto_renewed_count'])->toBe(1);
    $refreshed = $subscription->fresh();
    expect($refreshed->status)->toBe('active')
        ->and($refreshed->term_end_date->toDateString())->toBe('2027-03-09')
        ->and($refreshed->versions()->where('change_type', 'renewal')->count())->toBe(1);

    // Applied once: a second run changes nothing.
    expect($this->renewalService->processExpiringSubscriptions((string) $this->tenant->id, 30)['auto_renewed_count'])->toBe(0);
});

test('MRR, ARR and the cash forecast come from the live subscriptions, without invented growth', function (): void {
    treasurySubscription(['current_period_start' => '2026-05-10']);

    expect($this->forecastingService->calculateMRR((string) $this->tenant->id))->toBe('200.00')
        ->and($this->forecastingService->calculateARR((string) $this->tenant->id))->toBe('2400.00');

    $forecast = $this->forecastingService->generateCashForecast((string) $this->tenant->id, 12);

    expect($forecast['forecast_months'])->toBe(12)
        ->and($forecast['monthly_breakdown'])->toHaveCount(12)
        ->and($forecast['monthly_breakdown']['2026-05'])->toBe('2400.00')
        ->and($forecast['projected_cash_inflow'])->toBe('2400.00')
        ->and(TransactionalOutbox::query()->where('event_type', 'like', '%forecast%')->count())->toBeGreaterThanOrEqual(1);
});
