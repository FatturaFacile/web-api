<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SubscriptionBilling\Contracts\RecurringBillingServiceInterface;
use Modules\SubscriptionBilling\Contracts\SubscriptionLifecycleServiceInterface;
use Modules\SubscriptionBilling\DTOs\CancelSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\CreateSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\UpgradeSubscriptionDTO;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionVersion;
use Tests\Feature\SubscriptionBilling\Support\SubscriptionScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-03-01 09:00:00');
    $this->tenant = SubscriptionScenario::company();
    $this->customer = SubscriptionScenario::customer($this->tenant);
    $this->starterPlan = SubscriptionScenario::plan($this->tenant, ['name' => 'Starter', 'base_price' => '50.0000']);
    $this->proPlan = SubscriptionScenario::plan($this->tenant, ['name' => 'Pro', 'base_price' => '150.0000']);
    $this->service = app(SubscriptionLifecycleServiceInterface::class);
});

function lifecycleCreate(string $planId): Subscription
{
    return test()->service->createSubscription(new CreateSubscriptionDTO(
        tenantId: (string) test()->tenant->id,
        companyId: (string) test()->tenant->id,
        customerId: (string) test()->customer->id,
        planId: $planId,
        startDate: '2026-03-01',
    ));
}

test('the lifecycle service creates an active subscription with its plan item and initial version', function (): void {
    $subscription = lifecycleCreate($this->starterPlan->id);

    expect($subscription->status)->toBe('active')
        ->and((string) $subscription->mrr)->toBe('50.00')
        ->and((string) $subscription->arr)->toBe('600.00')
        ->and($subscription->items)->toHaveCount(1)
        ->and($subscription->term_end_date->toDateString())->toBe('2027-02-28')
        ->and(SubscriptionVersion::query()->where('subscription_id', $subscription->id)->value('change_type'))->toBe('initial');
});

test('an upgrade in a billed period records the proration on a new version', function (): void {
    $subscription = lifecycleCreate($this->starterPlan->id);
    app(RecurringBillingServiceInterface::class)->billSubscription($subscription->id, CarbonImmutable::today());

    $this->travelTo('2026-03-17 09:00:00');
    $upgraded = $this->service->upgradeSubscription(new UpgradeSubscriptionDTO($subscription->id, $this->proPlan->id));
    $version = SubscriptionVersion::query()->where('subscription_id', $subscription->id)->orderByDesc('version_number')->first();

    // (150 - 50) x 15 / 31 days.
    expect($upgraded->plan_id)->toBe($this->proPlan->id)
        ->and($version->version_number)->toBe(2)
        ->and((string) $version->mrr_before)->toBe('50.00')
        ->and((string) $version->mrr_after)->toBe('150.00')
        ->and((string) $version->proration_amount)->toBe('48.39');
});

test('suspend, reactivate and cancel move the status and the MRR', function (): void {
    $subscription = lifecycleCreate($this->starterPlan->id);

    expect($this->service->suspendSubscription($subscription->id, 'Billing hold')->status)->toBe('paused')
        ->and($this->service->reactivateSubscription($subscription->id)->status)->toBe('active');

    $cancelled = $this->service->cancelSubscription(new CancelSubscriptionDTO(subscriptionId: $subscription->id, reason: 'Customer request', reasonCode: 'unused'));

    expect($cancelled->status)->toBe('cancelled')
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($cancelled->cancellation_reason_code)->toBe('unused')
        ->and((string) $cancelled->mrr)->toBe('0.00')
        ->and((string) $cancelled->arr)->toBe('0.00');
});
