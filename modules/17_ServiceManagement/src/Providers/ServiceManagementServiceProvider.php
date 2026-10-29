<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\AssetManagement\Events\AssetMaintenanceCreatedEvent;
use Modules\AssetManagement\Events\MaintenanceCompletedEvent;
use Modules\CRM\Events\OpportunityWonEvent;
use Modules\HumanResources\Events\EmployeeHiredEvent;
use Modules\HumanResources\Events\EmployeeTerminatedEvent;
use Modules\HumanResources\Events\LeaveApprovedEvent;
use Modules\Invoicing\Events\SalesQuoteAcceptedEvent;
use Modules\Projects\Events\ProjectClosureReadyEvent;
use Modules\ServiceManagement\Contracts\IncidentManagementServiceInterface;
use Modules\ServiceManagement\Contracts\ProblemAndChangeServiceInterface;
use Modules\ServiceManagement\Contracts\SLAManagementServiceInterface;
use Modules\ServiceManagement\Contracts\TicketServiceInterface;
use Modules\ServiceManagement\Contracts\WorkOrderAndFieldServiceInterface;
use Modules\ServiceManagement\Events\ChangeApprovedEvent;
use Modules\ServiceManagement\Events\ChangeRequestCreatedEvent;
use Modules\ServiceManagement\Events\CustomerSatisfactionUpdatedEvent;
use Modules\ServiceManagement\Events\FieldServiceExpenseReadyEvent;
use Modules\ServiceManagement\Events\IncidentCreatedEvent;
use Modules\ServiceManagement\Events\IncidentResolvedEvent;
use Modules\ServiceManagement\Events\ProblemCreatedEvent;
use Modules\ServiceManagement\Events\ProblemResolvedEvent;
use Modules\ServiceManagement\Events\ServiceAccrualReadyEvent;
use Modules\ServiceManagement\Events\ServiceCashForecastEvent;
use Modules\ServiceManagement\Events\ServiceCostReadyEvent;
use Modules\ServiceManagement\Events\ServiceInvoiceReadyEvent;
use Modules\ServiceManagement\Events\ServiceLaborCostReadyEvent;
use Modules\ServiceManagement\Events\ServicePipelineForecastEvent;
use Modules\ServiceManagement\Events\ServiceRevenueForecastEvent;
use Modules\ServiceManagement\Events\ServiceRevenueReadyEvent;
use Modules\ServiceManagement\Events\ServiceVisitCompletedEvent;
use Modules\ServiceManagement\Events\TicketAssignedEvent;
use Modules\ServiceManagement\Events\TicketClosedEvent;
use Modules\ServiceManagement\Events\TicketCreatedEvent;
use Modules\ServiceManagement\Events\TicketEscalatedEvent;
use Modules\ServiceManagement\Events\TicketResolvedEvent;
use Modules\ServiceManagement\Events\WorkOrderCompletedEvent;
use Modules\ServiceManagement\Events\WorkOrderCreatedEvent;
use Modules\ServiceManagement\Listeners\AssetMaintenanceESMListener;
use Modules\ServiceManagement\Listeners\CRMCustomerServiceListener;
use Modules\ServiceManagement\Listeners\DispatchOutboxOnESMEventListener;
use Modules\ServiceManagement\Listeners\HRAvailabilityListener;
use Modules\ServiceManagement\Listeners\ProjectESMListener;
use Modules\ServiceManagement\Listeners\WorkflowApprovalESMListener;
use Modules\ServiceManagement\Models\ChangeRequest;
use Modules\ServiceManagement\Models\Incident;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\WorkOrder;
use Modules\ServiceManagement\Policies\ChangeRequestPolicy;
use Modules\ServiceManagement\Policies\IncidentPolicy;
use Modules\ServiceManagement\Policies\TicketPolicy;
use Modules\ServiceManagement\Policies\WorkOrderPolicy;
use Modules\ServiceManagement\Services\IncidentManagementService;
use Modules\ServiceManagement\Services\ProblemAndChangeService;
use Modules\ServiceManagement\Services\SLAManagementService;
use Modules\ServiceManagement\Services\TicketService;
use Modules\ServiceManagement\Services\WorkOrderAndFieldService;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

final class ServiceManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TicketServiceInterface::class, TicketService::class);
        $this->app->singleton(IncidentManagementServiceInterface::class, IncidentManagementService::class);
        $this->app->singleton(ProblemAndChangeServiceInterface::class, ProblemAndChangeService::class);
        $this->app->singleton(WorkOrderAndFieldServiceInterface::class, WorkOrderAndFieldService::class);
        $this->app->singleton(SLAManagementServiceInterface::class, SLAManagementService::class);
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/api.php');
        });

        Gate::policy(Ticket::class, TicketPolicy::class);
        Gate::policy(Incident::class, IncidentPolicy::class);
        Gate::policy(ChangeRequest::class, ChangeRequestPolicy::class);
        Gate::policy(WorkOrder::class, WorkOrderPolicy::class);

        $domainEvents = [
            TicketCreatedEvent::class,
            TicketAssignedEvent::class,
            TicketEscalatedEvent::class,
            TicketResolvedEvent::class,
            TicketClosedEvent::class,
            IncidentCreatedEvent::class,
            IncidentResolvedEvent::class,
            ProblemCreatedEvent::class,
            ProblemResolvedEvent::class,
            ChangeRequestCreatedEvent::class,
            ChangeApprovedEvent::class,
            WorkOrderCreatedEvent::class,
            WorkOrderCompletedEvent::class,
            ServiceVisitCompletedEvent::class,
            CustomerSatisfactionUpdatedEvent::class,
            ServiceInvoiceReadyEvent::class,
            ServiceCostReadyEvent::class,
            ServiceLaborCostReadyEvent::class,
            FieldServiceExpenseReadyEvent::class,
            ServiceRevenueReadyEvent::class,
            ServiceAccrualReadyEvent::class,
            ServiceCashForecastEvent::class,
            ServiceRevenueForecastEvent::class,
            ServicePipelineForecastEvent::class,
        ];

        foreach ($domainEvents as $eventClass) {
            Event::listen($eventClass, DispatchOutboxOnESMEventListener::class);
        }

        // Cross-Module Listeners
        Event::listen(OpportunityWonEvent::class, [CRMCustomerServiceListener::class, 'handleOpportunityWon']);
        Event::listen(SalesQuoteAcceptedEvent::class, [CRMCustomerServiceListener::class, 'handleSalesQuoteAccepted']);
        Event::listen(AssetMaintenanceCreatedEvent::class, [AssetMaintenanceESMListener::class, 'handleAssetMaintenanceCreated']);
        Event::listen(MaintenanceCompletedEvent::class, [AssetMaintenanceESMListener::class, 'handleMaintenanceCompleted']);
        Event::listen(ProjectClosureReadyEvent::class, [ProjectESMListener::class, 'handleProjectClosureReady']);
        Event::listen(EmployeeHiredEvent::class, [HRAvailabilityListener::class, 'handleEmployeeHired']);
        Event::listen(EmployeeTerminatedEvent::class, [HRAvailabilityListener::class, 'handleEmployeeTerminated']);
        Event::listen(LeaveApprovedEvent::class, [HRAvailabilityListener::class, 'handleLeaveApproved']);
        Event::listen(WorkflowInstanceCompletedEvent::class, [WorkflowApprovalESMListener::class, 'handleWorkflowApproved']);
        Event::listen(WorkflowInstanceRejectedEvent::class, [WorkflowApprovalESMListener::class, 'handleWorkflowRejected']);
    }
}
