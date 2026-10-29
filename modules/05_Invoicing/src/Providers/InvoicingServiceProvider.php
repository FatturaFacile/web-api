<?php

declare(strict_types=1);

namespace Modules\Invoicing\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Invoicing\Contracts\CustomerCreditServiceInterface;
use Modules\Invoicing\Contracts\CustomerLedgerServiceInterface;
use Modules\Invoicing\Contracts\DeliveryFulfillmentServiceInterface;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Contracts\PricingAndDiscountServiceInterface;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Contracts\SalesQuoteServiceInterface;
use Modules\Invoicing\Events\CreditNoteIssuedEvent;
use Modules\Invoicing\Events\CustomerCreditLimitUpdatedEvent;
use Modules\Invoicing\Events\DeliveryNoteIssuedEvent;
use Modules\Invoicing\Events\GoodsDispatchedEvent;
use Modules\Invoicing\Events\InvoiceApprovedEvent;
use Modules\Invoicing\Events\InvoiceDraftedEvent;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\Invoicing\Events\SalesOrderApprovedEvent;
use Modules\Invoicing\Events\SalesOrderCreatedEvent;
use Modules\Invoicing\Events\SalesOrderCreditCheckedEvent;
use Modules\Invoicing\Events\SalesOrderHoldPlacedEvent;
use Modules\Invoicing\Events\SalesOrderHoldReleasedEvent;
use Modules\Invoicing\Events\SalesOrderStockReservedEvent;
use Modules\Invoicing\Events\SalesQuoteAcceptedEvent;
use Modules\Invoicing\Events\SalesQuoteApprovedEvent;
use Modules\Invoicing\Events\SalesQuoteCreatedEvent;
use Modules\Invoicing\Events\SalesQuoteSubmittedEvent;
use Modules\Invoicing\Listeners\DispatchOutboxOnInvoicingEventListener;
use Modules\Invoicing\Listeners\ProjectsBillingEventListener;
use Modules\Invoicing\Listeners\TreasuryCollectionEventListener;
use Modules\Invoicing\Listeners\TriggerWorkflowOnQuoteSubmissionListener;
use Modules\Invoicing\Listeners\WorkflowApprovalInvoicingListener;
use Modules\Invoicing\Models\CustomerCreditProfile;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesQuote;
use Modules\Invoicing\Policies\CustomerCreditProfilePolicy;
use Modules\Invoicing\Policies\SalesDeliveryNotePolicy;
use Modules\Invoicing\Policies\SalesInvoicePolicy;
use Modules\Invoicing\Policies\SalesOrderPolicy;
use Modules\Invoicing\Policies\SalesQuotePolicy;
use Modules\Invoicing\Services\CustomerCreditService;
use Modules\Invoicing\Services\CustomerLedgerService;
use Modules\Invoicing\Services\DeliveryFulfillmentService;
use Modules\Invoicing\Services\InvoicingService;
use Modules\Invoicing\Services\PricingAndDiscountService;
use Modules\Invoicing\Services\SalesOrderService;
use Modules\Invoicing\Services\SalesQuoteService;
use Modules\Projects\Events\ProjectBillingReadyEvent;
use Modules\Treasury\Events\CollectionReceivedEvent;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

final class InvoicingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PricingAndDiscountServiceInterface::class, PricingAndDiscountService::class);
        $this->app->singleton(CustomerLedgerServiceInterface::class, CustomerLedgerService::class);
        $this->app->singleton(CustomerCreditServiceInterface::class, CustomerCreditService::class);
        $this->app->singleton(SalesQuoteServiceInterface::class, SalesQuoteService::class);
        $this->app->singleton(SalesOrderServiceInterface::class, SalesOrderService::class);
        $this->app->singleton(DeliveryFulfillmentServiceInterface::class, DeliveryFulfillmentService::class);
        $this->app->singleton(InvoicingServiceInterface::class, InvoicingService::class);
    }

    public function boot(): void
    {
        $this->registerPolicies();
        $this->registerEventConsumers();
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }

    private function registerPolicies(): void
    {
        Gate::policy(SalesQuote::class, SalesQuotePolicy::class);
        Gate::policy(SalesOrder::class, SalesOrderPolicy::class);
        Gate::policy(SalesDeliveryNote::class, SalesDeliveryNotePolicy::class);
        Gate::policy(SalesInvoice::class, SalesInvoicePolicy::class);
        Gate::policy(CustomerCreditProfile::class, CustomerCreditProfilePolicy::class);
    }

    private function registerEventConsumers(): void
    {
        // 1. Module 15 Projects -> Milestone Billing
        Event::listen(ProjectBillingReadyEvent::class, [ProjectsBillingEventListener::class, 'handle']);

        // 2. Module 21 Workflow Engine -> Order/Quote/Invoice Approvals
        Event::listen(SalesQuoteSubmittedEvent::class, [TriggerWorkflowOnQuoteSubmissionListener::class, 'handle']);
        Event::listen(WorkflowInstanceCompletedEvent::class, [WorkflowApprovalInvoicingListener::class, 'handleWorkflowApproved']);
        Event::listen(WorkflowInstanceRejectedEvent::class, [WorkflowApprovalInvoicingListener::class, 'handleWorkflowRejected']);

        // 3. Module 24 Treasury -> Collections Clearing
        Event::listen(CollectionReceivedEvent::class, [TreasuryCollectionEventListener::class, 'handle']);

        // 4. Invoicing Domain Events -> Transactional Outbox
        Event::listen(SalesQuoteCreatedEvent::class, DispatchOutboxOnInvoicingEventListener::class);
        Event::listen(SalesQuoteSubmittedEvent::class, DispatchOutboxOnInvoicingEventListener::class);
        Event::listen(SalesQuoteApprovedEvent::class, DispatchOutboxOnInvoicingEventListener::class);
        Event::listen(SalesQuoteAcceptedEvent::class, DispatchOutboxOnInvoicingEventListener::class);
        Event::listen(SalesOrderCreatedEvent::class, DispatchOutboxOnInvoicingEventListener::class);
        Event::listen(SalesOrderCreditCheckedEvent::class, DispatchOutboxOnInvoicingEventListener::class);
        Event::listen(SalesOrderHoldPlacedEvent::class, DispatchOutboxOnInvoicingEventListener::class);
        Event::listen(SalesOrderHoldReleasedEvent::class, DispatchOutboxOnInvoicingEventListener::class);
        Event::listen(SalesOrderApprovedEvent::class, DispatchOutboxOnInvoicingEventListener::class);
        Event::listen(SalesOrderStockReservedEvent::class, DispatchOutboxOnInvoicingEventListener::class);
        Event::listen(DeliveryNoteIssuedEvent::class, DispatchOutboxOnInvoicingEventListener::class);
        Event::listen(GoodsDispatchedEvent::class, DispatchOutboxOnInvoicingEventListener::class);
        Event::listen(InvoiceDraftedEvent::class, DispatchOutboxOnInvoicingEventListener::class);
        Event::listen(InvoiceApprovedEvent::class, DispatchOutboxOnInvoicingEventListener::class);
        Event::listen(InvoicePostedEvent::class, DispatchOutboxOnInvoicingEventListener::class);
        Event::listen(CreditNoteIssuedEvent::class, DispatchOutboxOnInvoicingEventListener::class);
        Event::listen(CustomerCreditLimitUpdatedEvent::class, DispatchOutboxOnInvoicingEventListener::class);
    }
}
