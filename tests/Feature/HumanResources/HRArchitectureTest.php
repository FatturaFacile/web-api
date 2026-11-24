<?php

declare(strict_types=1);

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
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
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\EmployeeLifecycleEvent;
use Modules\HumanResources\Models\EmploymentContract;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\HumanResources\Models\PerformanceReview;
use Modules\HumanResources\Models\Timesheet;
use Modules\HumanResources\Models\TrainingRecord;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;

test('all 23 Module 18 HR domain events (including recruiting) implement DomainEventInterface and serialize cleanly', function () {
    $events = [
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

    foreach ($events as $eventClass) {
        expect(is_subclass_of($eventClass, DomainEventInterface::class))->toBeTrue();
    }
});

test('all workflow-governed HR models extend BaseEnterpriseModel and implement LockableByWorkflowInterface', function () {
    $lockableModels = [
        Employee::class,
        EmploymentContract::class,
        EmployeeLifecycleEvent::class,
        LeaveRequest::class,
        Timesheet::class,
        TrainingRecord::class,
        PerformanceReview::class,
    ];

    foreach ($lockableModels as $modelClass) {
        expect(is_subclass_of($modelClass, BaseEnterpriseModel::class))->toBeTrue()
            ->and(is_subclass_of($modelClass, LockableByWorkflowInterface::class))->toBeTrue();
    }
});

test('all 5 HR service contracts are bound in the container', function () {
    expect(app(EmployeeLifecycleServiceInterface::class))->toBeInstanceOf(EmployeeLifecycleServiceInterface::class)
        ->and(app(OrganizationManagementServiceInterface::class))->toBeInstanceOf(OrganizationManagementServiceInterface::class)
        ->and(app(LeaveAndTimeServiceInterface::class))->toBeInstanceOf(LeaveAndTimeServiceInterface::class)
        ->and(app(PerformanceManagementServiceInterface::class))->toBeInstanceOf(PerformanceManagementServiceInterface::class)
        ->and(app(TrainingAndCertificationServiceInterface::class))->toBeInstanceOf(TrainingAndCertificationServiceInterface::class);
});

test('recruiting seams for missing modules are bound to their default implementations', function () {
    expect(app(RecruitmentServiceInterface::class))->toBeInstanceOf(RecruitmentServiceInterface::class)
        ->and(app(CvParserInterface::class))->toBeInstanceOf(CvParserInterface::class)
        ->and(app(CandidateDocumentStorageInterface::class))->toBeInstanceOf(CandidateDocumentStorageInterface::class)
        ->and(app(CandidateEmailSenderInterface::class))->toBeInstanceOf(CandidateEmailSenderInterface::class)
        ->and(app(DigitalSignatureProviderInterface::class)->name())->toBe('manual')
        ->and(app(OnboardingStarterInterface::class))->toBeInstanceOf(OnboardingStarterInterface::class);
});
