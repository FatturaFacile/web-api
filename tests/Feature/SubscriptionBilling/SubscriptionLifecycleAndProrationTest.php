<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Contracts\SubscriptionLifecycleServiceInterface;
use Modules\SubscriptionBilling\DTOs\CancelSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\CreateSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\UpgradeSubscriptionDTO;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;
use Modules\SubscriptionBilling\Models\SubscriptionVersion;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->tenant);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->starterPlan = SubscriptionPlan::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_code' => 'TEST-STARTER',
        'name' => 'Starter Plan',
        'billing_interval' => 'monthly',
        'base_price' => 50.00,
    ]);

    $this->proPlan = SubscriptionPlan::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_code' => 'TEST-PRO',
        'name' => 'Pro Plan',
        'billing_interval' => 'monthly',
        'base_price' => 150.00,
    ]);

    $this->service = app(SubscriptionLifecycleServiceInterface::class);
});

test('subscription lifecycle service creates active subscription with items and initial version', function () {
    $customerId = (string) Str::uuid7();

    $dto = new CreateSubscriptionDTO(
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id,
        customerId: $customerId,
        planId: $this->starterPlan->id,
        startDate: now()->toDateString(),
        contractTermMonths: 12,
        autoRenew: true,
        items: [
            ['item_type' => 'addon', 'description' => 'Extra 100GB Storage', 'quantity' => 1, 'unit_price' => 20.00],
        ]
    );

    $sub = $this->service->createSubscription($dto, (string) $this->user->id);

    expect($sub->status)->toBe('active')
        ->and($sub->plan_id)->toBe($this->starterPlan->id)
        ->and((float) $sub->mrr)->toBe(50.00)
        ->and((float) $sub->arr)->toBe(600.00)
        ->and($sub->items()->count())->toBe(2);

    $version = SubscriptionVersion::where('subscription_id', $sub->id)->first();
    expect($version)->not->toBeNull()
        ->and($version->version_number)->toBe(1)
        ->and($version->change_type)->toBe('initial')
        ->and((float) $version->mrr_delta)->toBe(50.00);
});

test('subscription upgrade calculates proration and increments version number', function () {
    $customerId = (string) Str::uuid7();

    $start = now()->startOfMonth();
    $dto = new CreateSubscriptionDTO(
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id,
        customerId: $customerId,
        planId: $this->starterPlan->id,
        startDate: $start->toDateString(),
    );

    $sub = $this->service->createSubscription($dto);

    // Upgrade halfway through month
    $midMonth = $start->copy()->addDays(15);
    $upgradeDto = new UpgradeSubscriptionDTO(
        subscriptionId: $sub->id,
        newPlanId: $this->proPlan->id,
        effectiveDate: $midMonth->toDateString(),
        changeReason: 'Team expansion upgrade'
    );

    $upgraded = $this->service->upgradeSubscription($upgradeDto);

    expect($upgraded->plan_id)->toBe($this->proPlan->id)
        ->and((float) $upgraded->mrr)->toBe(150.00)
        ->and((float) $upgraded->arr)->toBe(1800.00);

    $latestVersion = SubscriptionVersion::where('subscription_id', $sub->id)
        ->where('version_number', 2)
        ->first();

    expect($latestVersion)->not->toBeNull()
        ->and($latestVersion->change_type)->toBe('upgrade')
        ->and((float) $latestVersion->mrr_before)->toBe(50.00)
        ->and((float) $latestVersion->mrr_after)->toBe(150.00)
        ->and((float) $latestVersion->mrr_delta)->toBe(100.00)
        ->and((float) $latestVersion->proration_amount)->toBeGreaterThan(0.00);
});

test('subscription pause, reactivate, and cancel transitions state cleanly', function () {
    $customerId = (string) Str::uuid7();
    $dto = new CreateSubscriptionDTO(
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id,
        customerId: $customerId,
        planId: $this->starterPlan->id,
        startDate: now()->toDateString(),
    );

    $sub = $this->service->createSubscription($dto);

    // 1. Suspend
    $suspended = $this->service->suspendSubscription($sub->id, 'Delinquent billing hold');
    expect($suspended->status)->toBe('paused');

    // 2. Reactivate
    $reactivated = $this->service->reactivateSubscription($sub->id);
    expect($reactivated->status)->toBe('active');

    // 3. Cancel
    $cancelDto = new CancelSubscriptionDTO(
        subscriptionId: $sub->id,
        reason: 'Customer requested cancellation'
    );

    $cancelled = $this->service->cancelSubscription($cancelDto);
    expect($cancelled->status)->toBe('cancelled')
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and((float) $cancelled->mrr)->toBe(0.00)
        ->and((float) $cancelled->arr)->toBe(0.00);
});
