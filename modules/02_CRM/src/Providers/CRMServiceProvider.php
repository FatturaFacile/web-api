<?php

declare(strict_types=1);

namespace Modules\CRM\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\CRM\Contracts\ActivityServiceInterface;
use Modules\CRM\Contracts\CampaignServiceInterface;
use Modules\CRM\Contracts\CustomerHealthServiceInterface;
use Modules\CRM\Contracts\ForecastServiceInterface;
use Modules\CRM\Contracts\LeadServiceInterface;
use Modules\CRM\Contracts\OpportunityServiceInterface;
use Modules\CRM\Events\ActivityCompletedEvent;
use Modules\CRM\Events\CustomerHealthChangedEvent;
use Modules\CRM\Events\LeadConvertedEvent;
use Modules\CRM\Events\LeadCreatedEvent;
use Modules\CRM\Events\LeadQualifiedEvent;
use Modules\CRM\Events\LeadRejectedEvent;
use Modules\CRM\Events\MeetingScheduledEvent;
use Modules\CRM\Events\OpportunityCreatedEvent;
use Modules\CRM\Events\OpportunityLostEvent;
use Modules\CRM\Events\OpportunityStageChangedEvent;
use Modules\CRM\Events\OpportunityWonEvent;
use Modules\CRM\Events\ProjectLeadQualifiedEvent;
use Modules\CRM\Events\ProjectOpportunityCreatedEvent;
use Modules\CRM\Events\QuoteRequestedEvent;
use Modules\CRM\Listeners\DispatchOutboxOnCRMEventListener;
use Modules\CRM\Listeners\SalesEventListener;
use Modules\CRM\Listeners\WorkflowApprovalCRMListener;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Activity;
use Modules\CRM\Models\Campaign;
use Modules\CRM\Models\Forecast;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Policies\AccountPolicy;
use Modules\CRM\Policies\ActivityPolicy;
use Modules\CRM\Policies\CampaignPolicy;
use Modules\CRM\Policies\ForecastPolicy;
use Modules\CRM\Policies\LeadPolicy;
use Modules\CRM\Policies\OpportunityPolicy;
use Modules\CRM\Services\ActivityService;
use Modules\CRM\Services\CampaignService;
use Modules\CRM\Services\CustomerHealthService;
use Modules\CRM\Services\ForecastService;
use Modules\CRM\Services\LeadService;
use Modules\CRM\Services\OpportunityService;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\Invoicing\Events\SalesOrderCreatedEvent;
use Modules\Invoicing\Events\SalesQuoteAcceptedEvent;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

final class CRMServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LeadServiceInterface::class, LeadService::class);
        $this->app->singleton(OpportunityServiceInterface::class, OpportunityService::class);
        $this->app->singleton(ActivityServiceInterface::class, ActivityService::class);
        $this->app->singleton(CustomerHealthServiceInterface::class, CustomerHealthService::class);
        $this->app->singleton(ForecastServiceInterface::class, ForecastService::class);
        $this->app->singleton(CampaignServiceInterface::class, CampaignService::class);
    }

    public function boot(): void
    {
        $this->registerPolicies();
        $this->registerEventListeners();
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }

    private function registerPolicies(): void
    {
        Gate::policy(Lead::class, LeadPolicy::class);
        Gate::policy(Opportunity::class, OpportunityPolicy::class);
        Gate::policy(Account::class, AccountPolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);
        Gate::policy(Forecast::class, ForecastPolicy::class);
        Gate::policy(Campaign::class, CampaignPolicy::class);
    }

    private function registerEventListeners(): void
    {
        // 1. CRM Domain Events -> Transactional Outbox
        Event::listen(LeadCreatedEvent::class, DispatchOutboxOnCRMEventListener::class);
        Event::listen(LeadQualifiedEvent::class, DispatchOutboxOnCRMEventListener::class);
        Event::listen(LeadConvertedEvent::class, DispatchOutboxOnCRMEventListener::class);
        Event::listen(LeadRejectedEvent::class, DispatchOutboxOnCRMEventListener::class);
        Event::listen(OpportunityCreatedEvent::class, DispatchOutboxOnCRMEventListener::class);
        Event::listen(OpportunityStageChangedEvent::class, DispatchOutboxOnCRMEventListener::class);
        Event::listen(OpportunityWonEvent::class, DispatchOutboxOnCRMEventListener::class);
        Event::listen(OpportunityLostEvent::class, DispatchOutboxOnCRMEventListener::class);
        Event::listen(MeetingScheduledEvent::class, DispatchOutboxOnCRMEventListener::class);
        Event::listen(ActivityCompletedEvent::class, DispatchOutboxOnCRMEventListener::class);
        Event::listen(CustomerHealthChangedEvent::class, DispatchOutboxOnCRMEventListener::class);
        Event::listen(QuoteRequestedEvent::class, DispatchOutboxOnCRMEventListener::class);
        Event::listen(ProjectOpportunityCreatedEvent::class, DispatchOutboxOnCRMEventListener::class);
        Event::listen(ProjectLeadQualifiedEvent::class, DispatchOutboxOnCRMEventListener::class);

        // 2. Module 05 Sales & Invoicing -> CRM Upstream Integration
        Event::listen(SalesQuoteAcceptedEvent::class, [SalesEventListener::class, 'handleQuoteAccepted']);
        Event::listen(SalesOrderCreatedEvent::class, [SalesEventListener::class, 'handleSalesOrderCreated']);
        Event::listen(InvoicePostedEvent::class, [SalesEventListener::class, 'handleInvoicePosted']);

        // 3. Module 21 Workflow Engine V2 -> CRM Approval Synchronization
        Event::listen(WorkflowInstanceCompletedEvent::class, [WorkflowApprovalCRMListener::class, 'handleWorkflowApproved']);
        Event::listen(WorkflowInstanceRejectedEvent::class, [WorkflowApprovalCRMListener::class, 'handleWorkflowRejected']);
    }
}
