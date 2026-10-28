<?php

declare(strict_types=1);

namespace Modules\Workflow\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Workflow\Contracts\ApprovalMatrixEvaluatorInterface;
use Modules\Workflow\Contracts\BusinessCalendarCalculatorInterface;
use Modules\Workflow\Contracts\ExternalActionTokenServiceInterface;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\Contracts\WorkflowWebhookDispatcherInterface;
use Modules\Workflow\Events\ApprovalTokenGeneratedEvent;
use Modules\Workflow\Events\FiscalDocumentLockedEvent;
use Modules\Workflow\Events\WorkflowDecisionRecordedEvent;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;
use Modules\Workflow\Events\WorkflowInstanceStartedEvent;
use Modules\Workflow\Events\WorkflowStepAssignedEvent;
use Modules\Workflow\Events\WorkflowStepEscalatedEvent;
use Modules\Workflow\Listeners\DispatchOutboxOnWorkflowEventListener;
use Modules\Workflow\Listeners\LockFiscalDocumentOnCompletionListener;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowDelegation;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;
use Modules\Workflow\Policies\WorkflowDefinitionPolicy;
use Modules\Workflow\Policies\WorkflowDelegationPolicy;
use Modules\Workflow\Policies\WorkflowInstancePolicy;
use Modules\Workflow\Services\ApprovalMatrixEvaluatorService;
use Modules\Workflow\Services\BusinessCalendarCalculationService;
use Modules\Workflow\Services\DocumentLockingBridgeService;
use Modules\Workflow\Services\ExternalActionTokenService;
use Modules\Workflow\Services\VotingThresholdEvaluationService;
use Modules\Workflow\Services\WorkflowAuditLedgerService;
use Modules\Workflow\Services\WorkflowEngineService;
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
    }

    public function boot(): void
    {
        // Load API Routes
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

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
        Event::listen(FiscalDocumentLockedEvent::class, DispatchOutboxOnWorkflowEventListener::class);
        Event::listen(ApprovalTokenGeneratedEvent::class, DispatchOutboxOnWorkflowEventListener::class);

        // Register Fiscal Document Locking
        Event::listen(WorkflowInstanceCompletedEvent::class, LockFiscalDocumentOnCompletionListener::class);
    }
}
