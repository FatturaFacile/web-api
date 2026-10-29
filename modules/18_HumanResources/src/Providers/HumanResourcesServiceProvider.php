<?php

declare(strict_types=1);

namespace Modules\HumanResources\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\AssetManagement\Events\AssetAssignedEvent;
use Modules\AssetManagement\Events\AssetDisposedEvent;
use Modules\AssetManagement\Events\AssetTransferredEvent;
use Modules\HumanResources\Contracts\EmployeeLifecycleServiceInterface;
use Modules\HumanResources\Contracts\LeaveAndTimeServiceInterface;
use Modules\HumanResources\Contracts\OrganizationManagementServiceInterface;
use Modules\HumanResources\Contracts\PerformanceManagementServiceInterface;
use Modules\HumanResources\Contracts\TrainingAndCertificationServiceInterface;
use Modules\HumanResources\Events\AccrualReadyEvent;
use Modules\HumanResources\Events\CertificationExpiringEvent;
use Modules\HumanResources\Events\EmployeeCostForecastEvent;
use Modules\HumanResources\Events\EmployeeCostReadyEvent;
use Modules\HumanResources\Events\EmployeeHiredEvent;
use Modules\HumanResources\Events\EmployeePromotedEvent;
use Modules\HumanResources\Events\EmployeeTerminatedEvent;
use Modules\HumanResources\Events\EmployeeTransferredEvent;
use Modules\HumanResources\Events\LaborCostReadyEvent;
use Modules\HumanResources\Events\LeaveApprovedEvent;
use Modules\HumanResources\Events\LeaveLiabilityReadyEvent;
use Modules\HumanResources\Events\LeaveRequestedEvent;
use Modules\HumanResources\Events\PayrollForecastEvent;
use Modules\HumanResources\Events\PerformanceReviewCompletedEvent;
use Modules\HumanResources\Events\TrainingAssignedEvent;
use Modules\HumanResources\Events\TrainingBudgetForecastEvent;
use Modules\HumanResources\Events\TrainingCostReadyEvent;
use Modules\HumanResources\Listeners\AssetAssignmentHRListener;
use Modules\HumanResources\Listeners\DispatchOutboxOnHREventListener;
use Modules\HumanResources\Listeners\ProjectHRListener;
use Modules\HumanResources\Listeners\WorkflowApprovalHRListener;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\HumanResources\Models\PerformanceReview;
use Modules\HumanResources\Models\TrainingRecord;
use Modules\HumanResources\Policies\DepartmentPolicy;
use Modules\HumanResources\Policies\EmployeePolicy;
use Modules\HumanResources\Policies\LeaveRequestPolicy;
use Modules\HumanResources\Policies\PerformanceReviewPolicy;
use Modules\HumanResources\Policies\TrainingRecordPolicy;
use Modules\HumanResources\Services\EmployeeLifecycleService;
use Modules\HumanResources\Services\LeaveAndTimeService;
use Modules\HumanResources\Services\OrganizationManagementService;
use Modules\HumanResources\Services\PerformanceManagementService;
use Modules\HumanResources\Services\TrainingAndCertificationService;
use Modules\Projects\Events\ProjectClosureReadyEvent;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

final class HumanResourcesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EmployeeLifecycleServiceInterface::class, EmployeeLifecycleService::class);
        $this->app->singleton(OrganizationManagementServiceInterface::class, OrganizationManagementService::class);
        $this->app->singleton(LeaveAndTimeServiceInterface::class, LeaveAndTimeService::class);
        $this->app->singleton(PerformanceManagementServiceInterface::class, PerformanceManagementService::class);
        $this->app->singleton(TrainingAndCertificationServiceInterface::class, TrainingAndCertificationService::class);
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(LeaveRequest::class, LeaveRequestPolicy::class);
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(PerformanceReview::class, PerformanceReviewPolicy::class);
        Gate::policy(TrainingRecord::class, TrainingRecordPolicy::class);

        $domainEvents = [
            EmployeeHiredEvent::class,
            EmployeePromotedEvent::class,
            EmployeeTransferredEvent::class,
            EmployeeTerminatedEvent::class,
            LeaveRequestedEvent::class,
            LeaveApprovedEvent::class,
            PerformanceReviewCompletedEvent::class,
            TrainingAssignedEvent::class,
            CertificationExpiringEvent::class,
            EmployeeCostReadyEvent::class,
            LaborCostReadyEvent::class,
            AccrualReadyEvent::class,
            TrainingCostReadyEvent::class,
            LeaveLiabilityReadyEvent::class,
            PayrollForecastEvent::class,
            TrainingBudgetForecastEvent::class,
            EmployeeCostForecastEvent::class,
        ];

        foreach ($domainEvents as $eventClass) {
            Event::listen($eventClass, DispatchOutboxOnHREventListener::class);
        }

        // Cross-Module Listeners
        Event::listen(AssetAssignedEvent::class, [AssetAssignmentHRListener::class, 'handleAssetAssigned']);
        Event::listen(AssetTransferredEvent::class, [AssetAssignmentHRListener::class, 'handleAssetTransferred']);
        Event::listen(AssetDisposedEvent::class, [AssetAssignmentHRListener::class, 'handleAssetDisposed']);
        Event::listen(ProjectClosureReadyEvent::class, [ProjectHRListener::class, 'handleProjectClosureReady']);
        Event::listen(WorkflowInstanceCompletedEvent::class, [WorkflowApprovalHRListener::class, 'handleWorkflowApproved']);
        Event::listen(WorkflowInstanceRejectedEvent::class, [WorkflowApprovalHRListener::class, 'handleWorkflowRejected']);
    }
}
