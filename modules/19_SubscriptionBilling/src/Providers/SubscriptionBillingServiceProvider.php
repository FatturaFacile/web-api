<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\CRM\Events\OpportunityWonEvent;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\ServiceManagement\Events\CustomerSatisfactionUpdatedEvent;
use Modules\SubscriptionBilling\Contracts\RecurringBillingServiceInterface;
use Modules\SubscriptionBilling\Contracts\RenewalServiceInterface;
use Modules\SubscriptionBilling\Contracts\RevenueRecognitionServiceInterface;
use Modules\SubscriptionBilling\Contracts\SubscriptionForecastingServiceInterface;
use Modules\SubscriptionBilling\Contracts\SubscriptionLifecycleServiceInterface;
use Modules\SubscriptionBilling\Contracts\UsageBillingServiceInterface;
use Modules\SubscriptionBilling\Events\ARRForecastEvent;
use Modules\SubscriptionBilling\Events\ARRUpdatedEvent;
use Modules\SubscriptionBilling\Events\DeferredRevenueReadyEvent;
use Modules\SubscriptionBilling\Events\InvoiceGeneratedEvent;
use Modules\SubscriptionBilling\Events\MRRForecastEvent;
use Modules\SubscriptionBilling\Events\MRRUpdatedEvent;
use Modules\SubscriptionBilling\Events\RecurringRevenueForecastEvent;
use Modules\SubscriptionBilling\Events\RenewalForecastEvent;
use Modules\SubscriptionBilling\Events\RevenueRecognitionReadyEvent;
use Modules\SubscriptionBilling\Events\RevenueRecognizedEvent;
use Modules\SubscriptionBilling\Events\SubscriptionActivatedEvent;
use Modules\SubscriptionBilling\Events\SubscriptionCancelledEvent;
use Modules\SubscriptionBilling\Events\SubscriptionCashForecastEvent;
use Modules\SubscriptionBilling\Events\SubscriptionCreatedEvent;
use Modules\SubscriptionBilling\Events\SubscriptionDowngradedEvent;
use Modules\SubscriptionBilling\Events\SubscriptionReactivatedEvent;
use Modules\SubscriptionBilling\Events\SubscriptionRenewedEvent;
use Modules\SubscriptionBilling\Events\SubscriptionSuspendedEvent;
use Modules\SubscriptionBilling\Events\SubscriptionUpgradedEvent;
use Modules\SubscriptionBilling\Events\UsageBillingReadyEvent;
use Modules\SubscriptionBilling\Events\UsageRatedEvent;
use Modules\SubscriptionBilling\Events\UsageRecordedEvent;
use Modules\SubscriptionBilling\Listeners\CRMSubscriptionListener;
use Modules\SubscriptionBilling\Listeners\DispatchOutboxOnSubscriptionEventListener;
use Modules\SubscriptionBilling\Listeners\SalesInvoiceSubscriptionListener;
use Modules\SubscriptionBilling\Listeners\ServiceManagementSubscriptionListener;
use Modules\SubscriptionBilling\Listeners\WorkflowApprovalSubscriptionListener;
use Modules\SubscriptionBilling\Models\RevenueContract;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;
use Modules\SubscriptionBilling\Models\UsageMetric;
use Modules\SubscriptionBilling\Policies\BillingPlanPolicy;
use Modules\SubscriptionBilling\Policies\RevenueContractPolicy;
use Modules\SubscriptionBilling\Policies\SubscriptionPolicy;
use Modules\SubscriptionBilling\Policies\UsageMetricPolicy;
use Modules\SubscriptionBilling\Services\RecurringBillingService;
use Modules\SubscriptionBilling\Services\RenewalService;
use Modules\SubscriptionBilling\Services\RevenueRecognitionService;
use Modules\SubscriptionBilling\Services\SubscriptionForecastingService;
use Modules\SubscriptionBilling\Services\SubscriptionLifecycleService;
use Modules\SubscriptionBilling\Services\UsageBillingService;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;

final class SubscriptionBillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SubscriptionLifecycleServiceInterface::class, SubscriptionLifecycleService::class);
        $this->app->bind(RecurringBillingServiceInterface::class, RecurringBillingService::class);
        $this->app->bind(UsageBillingServiceInterface::class, UsageBillingService::class);
        $this->app->bind(RevenueRecognitionServiceInterface::class, RevenueRecognitionService::class);
        $this->app->bind(RenewalServiceInterface::class, RenewalService::class);
        $this->app->bind(SubscriptionForecastingServiceInterface::class, SubscriptionForecastingService::class);
    }

    public function boot(): void
    {
        $this->registerPolicies();
        $this->registerRoutes();
        $this->registerEventListeners();
    }

    private function registerPolicies(): void
    {
        Gate::policy(Subscription::class, SubscriptionPolicy::class);
        Gate::policy(SubscriptionPlan::class, BillingPlanPolicy::class);
        Gate::policy(RevenueContract::class, RevenueContractPolicy::class);
        Gate::policy(UsageMetric::class, UsageMetricPolicy::class);
    }

    private function registerRoutes(): void
    {
        $routesPath = __DIR__.'/../../routes/api.php';
        if (file_exists($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }
    }

    private function registerEventListeners(): void
    {
        // Outbox delivery for all domain events
        $events = [
            SubscriptionCreatedEvent::class,
            SubscriptionActivatedEvent::class,
            SubscriptionSuspendedEvent::class,
            SubscriptionReactivatedEvent::class,
            SubscriptionRenewedEvent::class,
            SubscriptionCancelledEvent::class,
            SubscriptionUpgradedEvent::class,
            SubscriptionDowngradedEvent::class,
            UsageRecordedEvent::class,
            UsageRatedEvent::class,
            InvoiceGeneratedEvent::class,
            RevenueRecognizedEvent::class,
            MRRUpdatedEvent::class,
            ARRUpdatedEvent::class,
            RevenueRecognitionReadyEvent::class,
            DeferredRevenueReadyEvent::class,
            UsageBillingReadyEvent::class,
            SubscriptionCashForecastEvent::class,
            RenewalForecastEvent::class,
            RecurringRevenueForecastEvent::class,
            MRRForecastEvent::class,
            ARRForecastEvent::class,
        ];

        foreach ($events as $eventClass) {
            Event::listen($eventClass, DispatchOutboxOnSubscriptionEventListener::class);
        }

        // Cross-module listeners
        Event::listen(OpportunityWonEvent::class, CRMSubscriptionListener::class);
        Event::listen(InvoicePostedEvent::class, SalesInvoiceSubscriptionListener::class);
        Event::listen(CustomerSatisfactionUpdatedEvent::class, ServiceManagementSubscriptionListener::class);
        Event::listen(WorkflowInstanceCompletedEvent::class, WorkflowApprovalSubscriptionListener::class);
    }
}
