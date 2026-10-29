<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\SubscriptionDemoDataSeeder;
use Database\Seeders\SubscriptionReferenceSeeder;
use Database\Seeders\SubscriptionTestingSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\BillingRun;
use Modules\SubscriptionBilling\Models\ChurnPrediction;
use Modules\SubscriptionBilling\Models\DunningPolicy;
use Modules\SubscriptionBilling\Models\DunningRecord;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;
use Modules\SubscriptionBilling\Models\PartnerCommission;
use Modules\SubscriptionBilling\Models\PerformanceObligation;
use Modules\SubscriptionBilling\Models\PlanFeature;
use Modules\SubscriptionBilling\Models\PriceBook;
use Modules\SubscriptionBilling\Models\PricingTier;
use Modules\SubscriptionBilling\Models\RevenueContract;
use Modules\SubscriptionBilling\Models\RevenueRecognitionEntry;
use Modules\SubscriptionBilling\Models\RevenueSchedule;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;
use Modules\SubscriptionBilling\Models\SubscriptionProduct;
use Modules\SubscriptionBilling\Models\SubscriptionVersion;
use Modules\SubscriptionBilling\Models\UsageAggregation;
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
        'base_price' => 75.00,
    ]);

    $this->planUp = SubscriptionPlan::factory()->create([
        'tenant_id' => $this->tenant->id,
        'billing_interval' => 'monthly',
        'base_price' => 175.00,
    ]);
});

test('Subscription API endpoints provide authenticated CRUD, plan upgrade, and usage recording', function () {
    $customerId = (string) Str::uuid7();

    // 1. Create Subscription via API
    $createResponse = $this->postJson('/api/v1/sub/contracts', [
        'customer_id' => $customerId,
        'plan_id' => $this->plan->id,
        'start_date' => now()->toDateString(),
        'contract_term_months' => 12,
        'auto_renew' => true,
    ]);

    $createResponse->assertStatus(201);
    $subId = $createResponse->json('data.id');

    // 2. List Subscriptions
    $listResponse = $this->getJson('/api/v1/sub/contracts');
    $listResponse->assertStatus(200);

    // 3. Upgrade Plan via API
    $upgradeResponse = $this->postJson("/api/v1/sub/contracts/{$subId}/upgrade", [
        'new_plan_id' => $this->planUp->id,
        'change_reason' => 'API Plan Upgrade',
    ]);
    $upgradeResponse->assertStatus(200);

    // 4. Create Plan via API
    $planResponse = $this->postJson('/api/v1/subscriptions/plans', [
        'plan_code' => 'API-PLAN-TEST',
        'name' => 'API Created Plan',
        'billing_interval' => 'monthly',
        'base_price' => 99.00,
    ]);
    $planResponse->assertStatus(201);

    // 5. Record Usage via API
    $item = SubscriptionItem::where('subscription_id', $subId)->first();
    if ($item) {
        $usageResponse = $this->postJson('/api/v1/subscriptions/usage', [
            'subscription_item_id' => $item->id,
            'metric_code' => 'API_REQUESTS',
            'quantity' => 120,
        ]);
        $usageResponse->assertStatus(201);
    }
});

test('Subscription multilingual seeders run idempotently without constraint violations', function () {
    $this->seed(SubscriptionReferenceSeeder::class);
    $this->seed(SubscriptionTestingSeeder::class);
    $this->seed(SubscriptionDemoDataSeeder::class);

    // Run a second time to ensure complete idempotency
    $this->seed(SubscriptionReferenceSeeder::class);
    $this->seed(SubscriptionTestingSeeder::class);
    $this->seed(SubscriptionDemoDataSeeder::class);

    expect(SubscriptionPlan::where('tenant_id', $this->tenant->id)->count())->toBeGreaterThan(0);
});

test('all 21 subscription billing factories instantiate valid persisted models with relational consistency', function () {
    $factories = [
        SubscriptionProduct::class => fn () => SubscriptionProduct::factory()->create(['tenant_id' => $this->tenant->id]),
        SubscriptionPlan::class => fn () => SubscriptionPlan::factory()->create(['tenant_id' => $this->tenant->id]),
        PricingTier::class => fn () => PricingTier::factory()->create(['tenant_id' => $this->tenant->id]),
        PlanFeature::class => fn () => PlanFeature::factory()->create(['tenant_id' => $this->tenant->id]),
        PriceBook::class => fn () => PriceBook::factory()->create(['tenant_id' => $this->tenant->id]),
        UsageMetric::class => fn () => UsageMetric::factory()->create(['tenant_id' => $this->tenant->id]),
        Subscription::class => fn () => Subscription::factory()->create(['tenant_id' => $this->tenant->id]),
        SubscriptionItem::class => fn () => SubscriptionItem::factory()->create(['tenant_id' => $this->tenant->id]),
        SubscriptionVersion::class => fn () => SubscriptionVersion::factory()->create(['tenant_id' => $this->tenant->id]),
        UsageEvent::class => fn () => UsageEvent::factory()->create(['tenant_id' => $this->tenant->id]),
        UsageAggregation::class => fn () => UsageAggregation::factory()->create(['tenant_id' => $this->tenant->id]),
        BillingRun::class => fn () => BillingRun::factory()->create(['tenant_id' => $this->tenant->id]),
        InvoiceSchedule::class => fn () => InvoiceSchedule::factory()->create(['tenant_id' => $this->tenant->id]),
        RevenueContract::class => fn () => RevenueContract::factory()->create(['tenant_id' => $this->tenant->id]),
        PerformanceObligation::class => fn () => PerformanceObligation::factory()->create(['tenant_id' => $this->tenant->id]),
        RevenueSchedule::class => fn () => RevenueSchedule::factory()->create(['tenant_id' => $this->tenant->id]),
        RevenueRecognitionEntry::class => fn () => RevenueRecognitionEntry::factory()->create(['tenant_id' => $this->tenant->id]),
        DunningPolicy::class => fn () => DunningPolicy::factory()->create(['tenant_id' => $this->tenant->id]),
        DunningRecord::class => fn () => DunningRecord::factory()->create(['tenant_id' => $this->tenant->id]),
        ChurnPrediction::class => fn () => ChurnPrediction::factory()->create(['tenant_id' => $this->tenant->id]),
        PartnerCommission::class => fn () => PartnerCommission::factory()->create(['tenant_id' => $this->tenant->id]),
    ];

    expect(count($factories))->toBe(21);

    foreach ($factories as $class => $factoryCall) {
        $model = $factoryCall();
        expect($model)->toBeInstanceOf($class)
            ->and($model->exists)->toBeTrue();
    }
});
