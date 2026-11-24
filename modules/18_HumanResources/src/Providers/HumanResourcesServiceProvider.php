<?php

declare(strict_types=1);

namespace Modules\HumanResources\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\AssetManagement\Events\AssetAssignedEvent;
use Modules\AssetManagement\Events\AssetDisposedEvent;
use Modules\AssetManagement\Events\AssetTransferredEvent;
use Modules\HumanResources\Console\Commands\PurgeExpiredTimeRecordsCommand;
use Modules\HumanResources\Console\PurgeExpiredCandidateDataCommand;
use Modules\HumanResources\Contracts\CandidateDocumentStorageInterface;
use Modules\HumanResources\Contracts\CandidateEmailSenderInterface;
use Modules\HumanResources\Contracts\CvParserInterface;
use Modules\HumanResources\Contracts\DigitalSignatureProviderInterface;
use Modules\HumanResources\Contracts\EmployeeLifecycleServiceInterface;
use Modules\HumanResources\Contracts\LeaveAndTimeServiceInterface;
use Modules\HumanResources\Contracts\OnboardingStarterInterface;
use Modules\HumanResources\Contracts\OrganizationManagementServiceInterface;
use Modules\HumanResources\Contracts\PerformanceManagementServiceInterface;
use Modules\HumanResources\Contracts\RecruitmentServiceInterface;
use Modules\HumanResources\Contracts\TrainingAndCertificationServiceInterface;
use Modules\HumanResources\Events\AccrualReadyEvent;
use Modules\HumanResources\Events\CandidateApplicationReceivedEvent;
use Modules\HumanResources\Events\CandidateDataAnonymizedEvent;
use Modules\HumanResources\Events\CandidateHiredEvent;
use Modules\HumanResources\Events\CandidateStageChangedEvent;
use Modules\HumanResources\Events\CertificationExpiringEvent;
use Modules\HumanResources\Events\EmployeeCostForecastEvent;
use Modules\HumanResources\Events\EmployeeCostReadyEvent;
use Modules\HumanResources\Events\EmployeeHiredEvent;
use Modules\HumanResources\Events\EmployeePromotedEvent;
use Modules\HumanResources\Events\EmployeeTerminatedEvent;
use Modules\HumanResources\Events\EmployeeTransferredEvent;
use Modules\HumanResources\Events\JobOfferSentEvent;
use Modules\HumanResources\Events\JobOpeningPublishedEvent;
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
use Modules\HumanResources\Listeners\MirrorPublishedRosterIntoTimeTracking;
use Modules\HumanResources\Listeners\ProjectHRListener;
use Modules\HumanResources\Listeners\WorkflowApprovalHRListener;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\JobOpening;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\HumanResources\Models\PerformanceReview;
use Modules\HumanResources\Models\TimeAuditEntry;
use Modules\HumanResources\Models\TimeClockEvent;
use Modules\HumanResources\Models\TimeDailyRecord;
use Modules\HumanResources\Models\TimeRecordingProfile;
use Modules\HumanResources\Models\TrainingRecord;
use Modules\HumanResources\Policies\CandidateApplicationPolicy;
use Modules\HumanResources\Policies\DepartmentPolicy;
use Modules\HumanResources\Policies\EmployeePolicy;
use Modules\HumanResources\Policies\JobOpeningPolicy;
use Modules\HumanResources\Policies\LeaveRequestPolicy;
use Modules\HumanResources\Policies\PerformanceReviewPolicy;
use Modules\HumanResources\Policies\TimeTrackingPolicy;
use Modules\HumanResources\Policies\TrainingRecordPolicy;
use Modules\HumanResources\Services\EmployeeLifecycleService;
use Modules\HumanResources\Services\LaravelMailCandidateEmailSender;
use Modules\HumanResources\Services\LeaveAndTimeService;
use Modules\HumanResources\Services\ManualAcceptanceSignatureProvider;
use Modules\HumanResources\Services\NaiveTextCvParser;
use Modules\HumanResources\Services\NullOnboardingStarter;
use Modules\HumanResources\Services\OrganizationManagementService;
use Modules\HumanResources\Services\PerformanceManagementService;
use Modules\HumanResources\Services\PrivateDiskCandidateDocumentStorage;
use Modules\HumanResources\Services\RecruitmentService;
use Modules\HumanResources\Services\TrainingAndCertificationService;
use Modules\Projects\Events\ProjectClosureReadyEvent;
use Modules\ShiftPlanning\Domain\Events\RosterPublishedEvent;
use Modules\ShiftPlanning\Domain\Events\ShiftAssignmentFinalizedEvent;
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

        // Recruiting & ATS (Mod 070): seams for modules that do not exist yet, selected through config/recruiting.php
        $this->app->singleton(RecruitmentServiceInterface::class, RecruitmentService::class);
        $this->app->singleton(CandidateDocumentStorageInterface::class, PrivateDiskCandidateDocumentStorage::class);
        $this->app->singleton(CandidateEmailSenderInterface::class, LaravelMailCandidateEmailSender::class);
        $this->app->singleton(OnboardingStarterInterface::class, NullOnboardingStarter::class);
        $this->app->singleton(CvParserInterface::class, fn (): CvParserInterface => match (config('recruiting.cv_parser', 'naive')) {
            default => new NaiveTextCvParser,
        });
        $this->app->singleton(DigitalSignatureProviderInterface::class, fn (): DigitalSignatureProviderInterface => match (config('recruiting.signature_provider', 'manual')) {
            default => new ManualAcceptanceSignatureProvider,
        });
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
            $this->loadRoutesFrom(__DIR__.'/../../routes/workforce.php');
        });

        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'hr');

        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(LeaveRequest::class, LeaveRequestPolicy::class);
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(PerformanceReview::class, PerformanceReviewPolicy::class);
        Gate::policy(TrainingRecord::class, TrainingRecordPolicy::class);
        Gate::policy(TimeClockEvent::class, TimeTrackingPolicy::class);
        Gate::policy(TimeDailyRecord::class, TimeTrackingPolicy::class);
        Gate::policy(TimeAuditEntry::class, TimeTrackingPolicy::class);
        Gate::policy(TimeRecordingProfile::class, TimeTrackingPolicy::class);
        Gate::policy(JobOpening::class, JobOpeningPolicy::class);
        Gate::policy(CandidateApplication::class, CandidateApplicationPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeExpiredTimeRecordsCommand::class]);
        }

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
            JobOpeningPublishedEvent::class,
            CandidateApplicationReceivedEvent::class,
            CandidateStageChangedEvent::class,
            JobOfferSentEvent::class,
            CandidateHiredEvent::class,
            CandidateDataAnonymizedEvent::class,
        ];

        foreach ($domainEvents as $eventClass) {
            Event::listen($eventClass, DispatchOutboxOnHREventListener::class);
        }

        // Recruiting GDPR / nLPD retention: daily anonymisation of candidates with expired consent
        if ($this->app->runningInConsole()) {
            $this->commands([PurgeExpiredCandidateDataCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('hr:recruiting:purge-expired-candidates')->dailyAt('03:30')->onOneServer()->withoutOverlapping();
        });

        // Cross-Module Listeners
        Event::listen(AssetAssignedEvent::class, [AssetAssignmentHRListener::class, 'handleAssetAssigned']);
        Event::listen(AssetTransferredEvent::class, [AssetAssignmentHRListener::class, 'handleAssetTransferred']);
        Event::listen(AssetDisposedEvent::class, [AssetAssignmentHRListener::class, 'handleAssetDisposed']);
        Event::listen(ProjectClosureReadyEvent::class, [ProjectHRListener::class, 'handleProjectClosureReady']);
        Event::listen(WorkflowInstanceCompletedEvent::class, [WorkflowApprovalHRListener::class, 'handleWorkflowApproved']);
        Event::listen(WorkflowInstanceRejectedEvent::class, [WorkflowApprovalHRListener::class, 'handleWorkflowRejected']);
        // Mod 073 rosters feed the planned shifts of the time recording (Mod 072); payroll (Mod 077) reads the same rosters.
        Event::listen(RosterPublishedEvent::class, [MirrorPublishedRosterIntoTimeTracking::class, 'handleRosterPublished']);
        Event::listen(ShiftAssignmentFinalizedEvent::class, [MirrorPublishedRosterIntoTimeTracking::class, 'handleAssignmentFinalized']);
    }
}
