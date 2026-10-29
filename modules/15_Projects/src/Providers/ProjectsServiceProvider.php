<?php

declare(strict_types=1);

namespace Modules\Projects\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Inventory\Events\ProjectInventoryIssueEvent;
use Modules\Inventory\Events\ProjectInventoryReservationEvent;
use Modules\Inventory\Events\ProjectMaterialConsumptionEvent;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Events\PurchaseLiabilityReadyEvent;
use Modules\Procurement\Events\PurchaseOrderApprovedEvent;
use Modules\Projects\Contracts\EarnedValueCalculationServiceInterface;
use Modules\Projects\Contracts\JobCostingAccumulationServiceInterface;
use Modules\Projects\Contracts\ProjectBudgetServiceInterface;
use Modules\Projects\Contracts\ProjectChangeOrderServiceInterface;
use Modules\Projects\Contracts\ProjectExpenseServiceInterface;
use Modules\Projects\Contracts\ProjectForecastServiceInterface;
use Modules\Projects\Contracts\ProjectRevenueRecognitionServiceInterface;
use Modules\Projects\Contracts\ProjectTimesheetServiceInterface;
use Modules\Projects\Events\ProjectAccrualReadyEvent;
use Modules\Projects\Events\ProjectBillingReadyEvent;
use Modules\Projects\Events\ProjectBudgetExceededEvent;
use Modules\Projects\Events\ProjectCashFlowForecastEvent;
use Modules\Projects\Events\ProjectClosedEvent;
use Modules\Projects\Events\ProjectClosureReadyEvent;
use Modules\Projects\Events\ProjectCollectionForecastEvent;
use Modules\Projects\Events\ProjectCompletedEvent;
use Modules\Projects\Events\ProjectCostReadyEvent;
use Modules\Projects\Events\ProjectCostVarianceDetectedEvent;
use Modules\Projects\Events\ProjectCreatedEvent;
use Modules\Projects\Events\ProjectExpenseApprovedEvent;
use Modules\Projects\Events\ProjectExpensePostingReadyEvent;
use Modules\Projects\Events\ProjectForecastUpdatedEvent;
use Modules\Projects\Events\ProjectLabourCostReadyEvent;
use Modules\Projects\Events\ProjectMarginCalculatedEvent;
use Modules\Projects\Events\ProjectMaterialConsumedEvent;
use Modules\Projects\Events\ProjectMaterialCostReadyEvent;
use Modules\Projects\Events\ProjectMilestoneCompletedEvent;
use Modules\Projects\Events\ProjectPausedEvent;
use Modules\Projects\Events\ProjectPaymentForecastEvent;
use Modules\Projects\Events\ProjectPhaseCompletedEvent;
use Modules\Projects\Events\ProjectReceivableReadyEvent;
use Modules\Projects\Events\ProjectResumedEvent;
use Modules\Projects\Events\ProjectRevenueReadyEvent;
use Modules\Projects\Events\ProjectRevenueRecognitionReadyEvent;
use Modules\Projects\Events\ProjectRevenueRecognizedEvent;
use Modules\Projects\Events\ProjectRiskDetectedEvent;
use Modules\Projects\Events\ProjectStartedEvent;
use Modules\Projects\Events\ProjectTimesheetApprovedEvent;
use Modules\Projects\Events\ProjectWipReadyEvent;
use Modules\Projects\Listeners\DispatchOutboxOnProjectEventListener;
use Modules\Projects\Listeners\InventoryIntegrationListener;
use Modules\Projects\Listeners\ProcurementIntegrationListener;
use Modules\Projects\Listeners\WorkflowApprovalProjectListener;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBudget;
use Modules\Projects\Models\ProjectChangeOrder;
use Modules\Projects\Models\ProjectTask;
use Modules\Projects\Models\ProjectTimesheet;
use Modules\Projects\Policies\ProjectBudgetPolicy;
use Modules\Projects\Policies\ProjectChangeOrderPolicy;
use Modules\Projects\Policies\ProjectPolicy;
use Modules\Projects\Policies\ProjectTaskPolicy;
use Modules\Projects\Policies\ProjectTimesheetPolicy;
use Modules\Projects\Services\EarnedValueCalculationService;
use Modules\Projects\Services\JobCostingAccumulationService;
use Modules\Projects\Services\ProjectBudgetService;
use Modules\Projects\Services\ProjectChangeOrderService;
use Modules\Projects\Services\ProjectExpenseService;
use Modules\Projects\Services\ProjectForecastService;
use Modules\Projects\Services\ProjectRevenueRecognitionService;
use Modules\Projects\Services\ProjectTimesheetService;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

final class ProjectsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(JobCostingAccumulationServiceInterface::class, JobCostingAccumulationService::class);
        $this->app->singleton(EarnedValueCalculationServiceInterface::class, EarnedValueCalculationService::class);
        $this->app->singleton(ProjectBudgetServiceInterface::class, ProjectBudgetService::class);
        $this->app->singleton(ProjectChangeOrderServiceInterface::class, ProjectChangeOrderService::class);
        $this->app->singleton(ProjectTimesheetServiceInterface::class, ProjectTimesheetService::class);
        $this->app->singleton(ProjectRevenueRecognitionServiceInterface::class, ProjectRevenueRecognitionService::class);
        $this->app->singleton(ProjectForecastServiceInterface::class, ProjectForecastService::class);
        $this->app->singleton(ProjectExpenseServiceInterface::class, ProjectExpenseService::class);
    }

    public function boot(): void
    {
        $this->registerPolicies();
        $this->registerEventListeners();
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }

    private function registerPolicies(): void
    {
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(ProjectTask::class, ProjectTaskPolicy::class);
        Gate::policy(ProjectBudget::class, ProjectBudgetPolicy::class);
        Gate::policy(ProjectChangeOrder::class, ProjectChangeOrderPolicy::class);
        Gate::policy(ProjectTimesheet::class, ProjectTimesheetPolicy::class);
    }

    private function registerEventListeners(): void
    {
        // Outbox Relaying for all Project Domain Events
        $domainEvents = [
            ProjectCreatedEvent::class,
            ProjectStartedEvent::class,
            ProjectPhaseCompletedEvent::class,
            ProjectCompletedEvent::class,
            ProjectClosedEvent::class,
            ProjectBudgetExceededEvent::class,
            ProjectCostVarianceDetectedEvent::class,
            ProjectMaterialConsumedEvent::class,
            ProjectTimesheetApprovedEvent::class,
            ProjectExpenseApprovedEvent::class,
            ProjectRevenueRecognizedEvent::class,
            ProjectCostReadyEvent::class,
            ProjectRevenueReadyEvent::class,
            ProjectWipReadyEvent::class,
            ProjectAccrualReadyEvent::class,
            ProjectMarginCalculatedEvent::class,
            ProjectClosureReadyEvent::class,
            ProjectCashFlowForecastEvent::class,
            ProjectBillingReadyEvent::class,
            ProjectCollectionForecastEvent::class,
            ProjectPausedEvent::class,
            ProjectResumedEvent::class,
            ProjectMilestoneCompletedEvent::class,
            ProjectRiskDetectedEvent::class,
            ProjectForecastUpdatedEvent::class,
            ProjectRevenueRecognitionReadyEvent::class,
            ProjectExpensePostingReadyEvent::class,
            ProjectLabourCostReadyEvent::class,
            ProjectMaterialCostReadyEvent::class,
            ProjectPaymentForecastEvent::class,
            ProjectReceivableReadyEvent::class,
        ];

        foreach ($domainEvents as $eventClass) {
            Event::listen($eventClass, DispatchOutboxOnProjectEventListener::class);
        }

        // Cross-Module Inventory Integrations
        Event::listen(ProjectMaterialConsumptionEvent::class, [InventoryIntegrationListener::class, 'handleMaterialConsumption']);
        Event::listen(ProjectInventoryIssueEvent::class, [InventoryIntegrationListener::class, 'handleInventoryIssue']);
        Event::listen(ProjectInventoryReservationEvent::class, [InventoryIntegrationListener::class, 'handleInventoryReservation']);

        // Cross-Module Procurement Integrations
        Event::listen(PurchaseOrderApprovedEvent::class, [ProcurementIntegrationListener::class, 'handlePurchaseOrderApproved']);
        Event::listen(GoodsReceivedEvent::class, [ProcurementIntegrationListener::class, 'handleGoodsReceived']);
        Event::listen(PurchaseLiabilityReadyEvent::class, [ProcurementIntegrationListener::class, 'handlePurchaseLiability']);

        // Cross-Module Workflow Approvals
        Event::listen(WorkflowInstanceCompletedEvent::class, [WorkflowApprovalProjectListener::class, 'handleCompleted']);
        Event::listen(WorkflowInstanceRejectedEvent::class, [WorkflowApprovalProjectListener::class, 'handleRejected']);
    }
}
