<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\LeaveManagement\Domain\Contracts\LeaveAvailabilityPortInterface;
use Modules\LeaveManagement\Domain\Contracts\PayrollAbsenceFeedPortInterface;
use Modules\LeaveManagement\Domain\Contracts\TimesheetAbsenceSyncPortInterface;
use Modules\LeaveManagement\Listeners\WorkflowApprovalLeaveListener;
use Modules\LeaveManagement\Models\DepartmentStaffingThreshold;
use Modules\LeaveManagement\Models\EmployeeLeaveAccrualLedger;
use Modules\LeaveManagement\Models\HolidayCalendar;
use Modules\LeaveManagement\Models\LeaveEntitlementPolicy;
use Modules\LeaveManagement\Models\LeaveRequest;
use Modules\LeaveManagement\Policies\LeaveManagementPolicy;
use Modules\LeaveManagement\Services\LeaveIntegrationService;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

/**
 * Mod 074 — Leave, Permits & Absences Management ServiceProvider (Issue #174).
 */
final class LeaveManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LeaveAvailabilityPortInterface::class, LeaveIntegrationService::class);
        $this->app->singleton(PayrollAbsenceFeedPortInterface::class, LeaveIntegrationService::class);
        $this->app->singleton(TimesheetAbsenceSyncPortInterface::class, LeaveIntegrationService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        Gate::policy(HolidayCalendar::class, LeaveManagementPolicy::class);
        Gate::policy(LeaveEntitlementPolicy::class, LeaveManagementPolicy::class);
        Gate::policy(EmployeeLeaveAccrualLedger::class, LeaveManagementPolicy::class);
        Gate::policy(DepartmentStaffingThreshold::class, LeaveManagementPolicy::class);
        Gate::policy(LeaveRequest::class, LeaveManagementPolicy::class);

        Event::listen(WorkflowInstanceCompletedEvent::class, [WorkflowApprovalLeaveListener::class, 'handleWorkflowApproved']);
        Event::listen(WorkflowInstanceRejectedEvent::class, [WorkflowApprovalLeaveListener::class, 'handleWorkflowRejected']);
    }
}
