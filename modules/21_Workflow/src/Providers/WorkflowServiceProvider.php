<?php

declare(strict_types=1);

namespace Modules\Workflow\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Workflow\Console\SeedDefaultWorkflowDefinitionsCommand;
use Modules\Workflow\Contracts\ApprovableDocument;
use Modules\Workflow\Contracts\ApprovalMatrixEvaluatorInterface;
use Modules\Workflow\Contracts\BusinessCalendarCalculatorInterface;
use Modules\Workflow\Contracts\ExternalActionTokenServiceInterface;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\Contracts\WorkflowWebhookDispatcherInterface;
use Modules\Workflow\Events\ApprovalTokenGeneratedEvent;
use Modules\Workflow\Events\FiscalDocumentLockedEvent;
use Modules\Workflow\Events\WorkflowChangesRequestedEvent;
use Modules\Workflow\Events\WorkflowDecisionRecordedEvent;
use Modules\Workflow\Events\WorkflowDelegationActivatedEvent;
use Modules\Workflow\Events\WorkflowInstanceBlockedEvent;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceExpiredEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;
use Modules\Workflow\Events\WorkflowInstanceStartedEvent;
use Modules\Workflow\Events\WorkflowInstanceWithdrawnEvent;
use Modules\Workflow\Events\WorkflowStepAssignedEvent;
use Modules\Workflow\Events\WorkflowStepEscalatedEvent;
use Modules\Workflow\Events\WorkflowStepReminderEvent;
use Modules\Workflow\Listeners\CreateDelegationFromLeaveListener;
use Modules\Workflow\Listeners\DispatchOutboxOnWorkflowEventListener;
use Modules\Workflow\Listeners\LockFiscalDocumentOnCompletionListener;
use Modules\Workflow\Listeners\NotifyWorkflowParticipantsListener;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowDelegation;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;
use Modules\Workflow\Policies\WorkflowDefinitionPolicy;
use Modules\Workflow\Policies\WorkflowDelegationPolicy;
use Modules\Workflow\Policies\WorkflowInstancePolicy;
use Modules\Workflow\Services\ApprovableRegistry;
use Modules\Workflow\Services\ApprovalGuardService;
use Modules\Workflow\Services\ApprovalMatrixEvaluatorService;
use Modules\Workflow\Services\ApprovalMatrixManager;
use Modules\Workflow\Services\ApprovalOutcomeNotifier;
use Modules\Workflow\Services\BusinessCalendarCalculationService;
use Modules\Workflow\Services\DocumentLockingBridgeService;
use Modules\Workflow\Services\ExternalActionTokenService;
use Modules\Workflow\Services\HolidayCalendarFactory;
use Modules\Workflow\Services\VotingThresholdEvaluationService;
use Modules\Workflow\Services\WorkflowAssigneeResolver;
use Modules\Workflow\Services\WorkflowAuditLedgerService;
use Modules\Workflow\Services\WorkflowDelegationResolver;
use Modules\Workflow\Services\WorkflowEngineService;
use Modules\Workflow\Services\WorkflowLifecycleService;
use Modules\Workflow\Services\WorkflowTimeoutProcessor;
use Modules\Workflow\Services\WorkflowWebhookDispatcherService;

final class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WorkflowEngineInterface::class, WorkflowEngineService::class);
        $this->app->singleton(WorkflowEngineService::class);

        $this->app->singleton(BusinessCalendarCalculatorInterface::class, BusinessCalendarCalculationService::class);
        $this->app->singleton(BusinessCalendarCalculationService::class);

        $this->app->singleton(ApprovalMatrixEvaluatorInterface::class, ApprovalMatrixEvaluatorService::class);
        $this->app->singleton(ApprovalMatrixEvaluatorService::class);

        $this->app->singleton(ExternalActionTokenServiceInterface::class, ExternalActionTokenService::class);
        $this->app->singleton(ExternalActionTokenService::class);

        $this->app->singleton(WorkflowWebhookDispatcherInterface::class, WorkflowWebhookDispatcherService::class);
        $this->app->singleton(WorkflowWebhookDispatcherService::class);

        $this->app->singleton(VotingThresholdEvaluationService::class);
        $this->app->singleton(WorkflowAuditLedgerService::class);
        $this->app->singleton(DocumentLockingBridgeService::class);
        $this->app->singleton(WorkflowAssigneeResolver::class);
        $this->app->singleton(WorkflowTimeoutProcessor::class);

        // Mod 006: consumer contract, control rules, lifecycle and the editors' services.
        $this->app->singleton(ApprovableRegistry::class);
        $this->app->singleton(ApprovalGuardService::class);
        $this->app->singleton(ApprovalOutcomeNotifier::class);
        $this->app->singleton(WorkflowDelegationResolver::class);
        $this->app->singleton(WorkflowLifecycleService::class);
        $this->app->singleton(ApprovalMatrixManager::class);
        $this->app->singleton(HolidayCalendarFactory::class);
    }

    public function boot(): void
    {
        // Load API Routes
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([SeedDefaultWorkflowDefinitionsCommand::class]);
        }

        // Register Policies
        Gate::policy(WorkflowDefinition::class, WorkflowDefinitionPolicy::class);
        Gate::policy(WorkflowInstance::class, WorkflowInstancePolicy::class);
        Gate::policy(WorkflowInstanceStep::class, WorkflowInstancePolicy::class);
        Gate::policy(WorkflowDelegation::class, WorkflowDelegationPolicy::class);

        // Register Outbox Relaying for Domain Events
        Event::listen(WorkflowInstanceStartedEvent::class, DispatchOutboxOnWorkflowEventListener::class);
        Event::listen(WorkflowStepAssignedEvent::class, DispatchOutboxOnWorkflowEventListener::class);
        Event::listen(WorkflowDecisionRecordedEvent::class, DispatchOutboxOnWorkflowEventListener::class);
        Event::listen(WorkflowInstanceCompletedEvent::class, DispatchOutboxOnWorkflowEventListener::class);
        Event::listen(WorkflowInstanceRejectedEvent::class, DispatchOutboxOnWorkflowEventListener::class);
        Event::listen(WorkflowStepEscalatedEvent::class, DispatchOutboxOnWorkflowEventListener::class);
        Event::listen(WorkflowStepReminderEvent::class, DispatchOutboxOnWorkflowEventListener::class);
        Event::listen(WorkflowInstanceExpiredEvent::class, DispatchOutboxOnWorkflowEventListener::class);
        Event::listen(FiscalDocumentLockedEvent::class, DispatchOutboxOnWorkflowEventListener::class);
        Event::listen(ApprovalTokenGeneratedEvent::class, DispatchOutboxOnWorkflowEventListener::class);

        Event::listen(WorkflowInstanceBlockedEvent::class, DispatchOutboxOnWorkflowEventListener::class);
        Event::listen(WorkflowChangesRequestedEvent::class, DispatchOutboxOnWorkflowEventListener::class);
        Event::listen(WorkflowInstanceWithdrawnEvent::class, DispatchOutboxOnWorkflowEventListener::class);
        Event::listen(WorkflowDelegationActivatedEvent::class, DispatchOutboxOnWorkflowEventListener::class);

        // Register Fiscal Document Locking
        Event::listen(WorkflowInstanceCompletedEvent::class, LockFiscalDocumentOnCompletionListener::class);

        // Notifications (Mod 008): new request, reminder, escalation, outcome, blocked, delegation activated.
        Event::listen(WorkflowStepAssignedEvent::class, [NotifyWorkflowParticipantsListener::class, 'handleStepAssigned']);
        Event::listen(WorkflowStepReminderEvent::class, [NotifyWorkflowParticipantsListener::class, 'handleReminder']);
        Event::listen(WorkflowStepEscalatedEvent::class, [NotifyWorkflowParticipantsListener::class, 'handleEscalated']);
        Event::listen(WorkflowInstanceCompletedEvent::class, [NotifyWorkflowParticipantsListener::class, 'handleCompleted']);
        Event::listen(WorkflowInstanceRejectedEvent::class, [NotifyWorkflowParticipantsListener::class, 'handleRejected']);
        Event::listen(WorkflowChangesRequestedEvent::class, [NotifyWorkflowParticipantsListener::class, 'handleChangesRequested']);
        Event::listen(WorkflowInstanceExpiredEvent::class, [NotifyWorkflowParticipantsListener::class, 'handleExpired']);
        Event::listen(WorkflowInstanceWithdrawnEvent::class, [NotifyWorkflowParticipantsListener::class, 'handleWithdrawn']);
        Event::listen(WorkflowInstanceBlockedEvent::class, [NotifyWorkflowParticipantsListener::class, 'handleBlocked']);
        Event::listen(WorkflowDelegationActivatedEvent::class, [NotifyWorkflowParticipantsListener::class, 'handleDelegationActivated']);

        // Approved absences (Mod 074) create the delegation to the manager when the tenant turned it on.
        Event::listen('Modules\LeaveManagement\Domain\Events\LeaveAbsenceApprovedEvent', CreateDelegationFromLeaveListener::class);

        $this->restartApprovalOnEdit();
    }

    /**
     * Modifying a key field (amount beyond tolerance, supplier, cost centre) of a document after it was approved voids the
     * approval and restarts the workflow. Only documents that adopted {@see ApprovableDocument} are watched, and a document
     * without an approved instance costs one indexed lookup.
     */
    private function restartApprovalOnEdit(): void
    {
        $this->app->booted(function (): void {
            foreach (app(ApprovableRegistry::class)->subjectTypes() as $subject) {
                $class = $subject['class'];

                if ($class === null || ! is_subclass_of($class, ApprovableDocument::class)) {
                    continue;
                }

                Event::listen('eloquent.updated: '.$class, static function (Model $document): void {
                    try {
                        app(WorkflowLifecycleService::class)->invalidateOnEdit($document, auth()->id() !== null ? (string) auth()->id() : null);
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                });
            }
        });
    }
}
