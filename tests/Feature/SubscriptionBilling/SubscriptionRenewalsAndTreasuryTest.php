<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\SubscriptionBilling\Contracts\RenewalServiceInterface;
use Modules\SubscriptionBilling\Contracts\SubscriptionForecastingServiceInterface;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->tenant);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->plan = SubscriptionPlan::factory()->create([
        'tenant_id' => $this->tenant->id,
        'billing_interval' => 'annual',
        'base_price' => 2400.00,
    ]);

    $this->renewalService = app(RenewalServiceInterface::class);
    $this->forecastingService = app(SubscriptionForecastingServiceInterface::class);
});

test('renewal service auto-renews expiring evergreen subscriptions and advances cycle end', function () {
    $expiringEnd = now()->addDays(10);
    $subscription = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => 'active',
        'auto_renew' => true,
        'contract_term_months' => 12,
        'current_period_start' => now()->subYear()->addDays(11)->toDateString(),
        'current_period_end' => $expiringEnd->toDateString(),
        'mrr' => 200.00,
        'arr' => 2400.00,
    ]);

    $result = $this->renewalService->processExpiringSubscriptions($this->tenant->id, 30);

    expect($result['auto_renewed_count'])->toBeGreaterThanOrEqual(1);

    $refreshed = $subscription->fresh();
    expect($refreshed->status)->toBe('active')
        ->and($refreshed->current_period_start->toDateString())->toBe($expiringEnd->copy()->addDay()->toDateString())
        ->and($refreshed->versions()->count())->toBeGreaterThanOrEqual(1);
});

test('subscription forecasting service computes MRR, ARR, and 12-month cash forecast', function () {
    Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => 'active',
        'mrr' => 500.00,
        'arr' => 6000.00,
    ]);

    $mrr = $this->forecastingService->calculateMRR($this->tenant->id);
    $arr = $this->forecastingService->calculateARR($this->tenant->id);
    $forecast = $this->forecastingService->generateCashForecast($this->tenant->id, 12);

    expect($mrr)->toBeGreaterThanOrEqual(500.00)
        ->and($arr)->toBeGreaterThanOrEqual(6000.00)
        ->and($forecast['forecast_months'])->toBe(12)
        ->and($forecast['projected_cash_inflow'])->toBeGreaterThan(0.00)
        ->and(count($forecast['monthly_breakdown']))->toBe(12);
});
