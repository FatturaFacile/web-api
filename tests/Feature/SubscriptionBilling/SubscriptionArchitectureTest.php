<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\SubscriptionBilling\Contracts\RecurringBillingServiceInterface;
use Modules\SubscriptionBilling\Contracts\RenewalServiceInterface;
use Modules\SubscriptionBilling\Contracts\RevenueRecognitionServiceInterface;
use Modules\SubscriptionBilling\Contracts\SubscriptionForecastingServiceInterface;
use Modules\SubscriptionBilling\Contracts\SubscriptionLifecycleServiceInterface;
use Modules\SubscriptionBilling\Contracts\UsageBillingServiceInterface;
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
use Modules\SubscriptionBilling\Providers\SubscriptionBillingServiceProvider;
use Modules\SubscriptionBilling\Services\RecurringBillingService;
use Modules\SubscriptionBilling\Services\RenewalService;
use Modules\SubscriptionBilling\Services\RevenueRecognitionService;
use Modules\SubscriptionBilling\Services\SubscriptionForecastingService;
use Modules\SubscriptionBilling\Services\SubscriptionLifecycleService;
use Modules\SubscriptionBilling\Services\UsageBillingService;

test('Module 19 Subscription Billing ServiceProvider is registered and binds all 6 domain interfaces', function () {
    expect(app()->providerIsLoaded(SubscriptionBillingServiceProvider::class))->toBeTrue()
        ->and(app(SubscriptionLifecycleServiceInterface::class))->toBeInstanceOf(SubscriptionLifecycleService::class)
        ->and(app(RecurringBillingServiceInterface::class))->toBeInstanceOf(RecurringBillingService::class)
        ->and(app(UsageBillingServiceInterface::class))->toBeInstanceOf(UsageBillingService::class)
        ->and(app(RevenueRecognitionServiceInterface::class))->toBeInstanceOf(RevenueRecognitionService::class)
        ->and(app(RenewalServiceInterface::class))->toBeInstanceOf(RenewalService::class)
        ->and(app(SubscriptionForecastingServiceInterface::class))->toBeInstanceOf(SubscriptionForecastingService::class);
});

test('all 21 subscription billing domain models exist and map to PostgreSQL tables', function () {
    $models = [
        SubscriptionProduct::class => 'sub_products',
        SubscriptionPlan::class => 'sub_plans',
        PricingTier::class => 'sub_pricing_tiers',
        PlanFeature::class => 'sub_plan_features',
        PriceBook::class => 'sub_price_books',
        UsageMetric::class => 'sub_usage_metrics',
        Subscription::class => 'sub_subscriptions',
        SubscriptionItem::class => 'sub_subscription_items',
        SubscriptionVersion::class => 'sub_subscription_versions',
        UsageEvent::class => 'sub_usage_events',
        UsageAggregation::class => 'sub_usage_aggregations',
        BillingRun::class => 'sub_billing_runs',
        InvoiceSchedule::class => 'sub_invoice_schedules',
        RevenueContract::class => 'sub_revenue_contracts',
        PerformanceObligation::class => 'sub_performance_obligations',
        RevenueSchedule::class => 'sub_revenue_schedules',
        RevenueRecognitionEntry::class => 'sub_revenue_recognition_entries',
        DunningPolicy::class => 'sub_dunning_policies',
        DunningRecord::class => 'sub_dunning_records',
        ChurnPrediction::class => 'sub_churn_predictions',
        PartnerCommission::class => 'sub_partner_commissions',
    ];

    expect(count($models))->toBe(21);

    foreach ($models as $class => $table) {
        $instance = new $class;
        expect($instance->getTable())->toBe($table);
    }
});

test('Module 19 API routes are registered under subscriptions and sub prefixes', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri());

    expect($routes->contains(fn ($uri) => str_contains($uri, 'api/v1/subscriptions/plans')))->toBeTrue()
        ->and($routes->contains(fn ($uri) => str_contains($uri, 'api/v1/subscriptions/usage')))->toBeTrue()
        ->and($routes->contains(fn ($uri) => str_contains($uri, 'api/v1/subscriptions/billing-runs')))->toBeTrue()
        ->and($routes->contains(fn ($uri) => str_contains($uri, 'api/v1/subscriptions/revenue-contracts')))->toBeTrue()
        ->and($routes->contains(fn ($uri) => str_contains($uri, 'api/v1/sub/contracts')))->toBeTrue();
});
