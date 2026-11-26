<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Workflow\Http\Controllers\ApprovalMatrixController;
use Modules\Workflow\Http\Controllers\BusinessCalendarController;
use Modules\Workflow\Http\Controllers\RemoteApprovalController;
use Modules\Workflow\Http\Controllers\WorkflowDecisionController;
use Modules\Workflow\Http\Controllers\WorkflowDefinitionController;
use Modules\Workflow\Http\Controllers\WorkflowDefinitionGraphController;
use Modules\Workflow\Http\Controllers\WorkflowDelegationController;
use Modules\Workflow\Http\Controllers\WorkflowEscalationRuleController;
use Modules\Workflow\Http\Controllers\WorkflowInboxController;
use Modules\Workflow\Http\Controllers\WorkflowInsightsController;
use Modules\Workflow\Http\Controllers\WorkflowInstanceActionsController;
use Modules\Workflow\Http\Controllers\WorkflowInstanceController;

Route::prefix('api/v1/workflows')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:workflow'])
    ->group(function (): void {
        // Workflow Definitions
        Route::get('definitions', [WorkflowDefinitionController::class, 'index'])->name('workflows.definitions.index');
        Route::get('definitions/{id}', [WorkflowDefinitionController::class, 'show'])->name('workflows.definitions.show');

        // Workflow Instances
        Route::get('instances', [WorkflowInstanceController::class, 'index'])->name('workflows.instances.index');
        Route::get('instances/{id}', [WorkflowInstanceController::class, 'show'])->name('workflows.instances.show');
        Route::post('instances', [WorkflowInstanceController::class, 'store'])->name('workflows.instances.store');
        Route::post('instances/{id}/cancel', [WorkflowInstanceController::class, 'cancel'])->name('workflows.instances.cancel');
        Route::get('instances/{id}/audit-trail', [WorkflowInstanceController::class, 'auditTrail'])->name('workflows.instances.audit-trail');
        Route::get('instances/{id}/audit-trail/verify', [WorkflowInstanceController::class, 'verifyAuditTrail'])->name('workflows.instances.audit-trail.verify');
        Route::post('instances/{id}/withdraw', [WorkflowInstanceActionsController::class, 'withdraw'])->name('workflows.instances.withdraw');
        Route::get('instances/{id}/timeline', [WorkflowInstanceActionsController::class, 'timeline'])->name('workflows.instances.timeline');
        Route::get('instances/{id}/evidence', [WorkflowInstanceActionsController::class, 'evidence'])->name('workflows.instances.evidence');

        // Pending Decisions & Actions
        Route::get('my-pending', [WorkflowDecisionController::class, 'myPending'])->name('workflows.decisions.my-pending');
        Route::get('my-tasks', [WorkflowInboxController::class, 'myTasks'])->name('workflows.inbox.my-tasks');
        Route::get('my-history', [WorkflowInboxController::class, 'myHistory'])->name('workflows.inbox.my-history');
        Route::get('my-requests', [WorkflowInboxController::class, 'myRequests'])->name('workflows.inbox.my-requests');
        Route::get('sla-report', [WorkflowInboxController::class, 'slaReport'])->name('workflows.inbox.sla-report');
        Route::get('sla-report/export', [WorkflowInsightsController::class, 'slaExport'])->name('workflows.inbox.sla-report.export');
        Route::get('pending-count', [WorkflowInsightsController::class, 'pendingCount'])->name('workflows.inbox.pending-count');
        Route::get('subject-types', [WorkflowInsightsController::class, 'subjectTypes'])->name('workflows.subject-types');

        // Approval matrices and business calendars: everybody reads and simulates, owners and administrators write (below).
        Route::get('matrices', [ApprovalMatrixController::class, 'index'])->name('workflows.matrices.index');
        Route::post('matrices/simulate', [ApprovalMatrixController::class, 'simulate'])->name('workflows.matrices.simulate');
        Route::get('matrices/{id}', [ApprovalMatrixController::class, 'show'])->whereUuid('id')->name('workflows.matrices.show');
        Route::get('calendars', [BusinessCalendarController::class, 'index'])->name('workflows.calendars.index');
        Route::get('calendars/{id}/holidays', [BusinessCalendarController::class, 'holidays'])->whereUuid('id')->name('workflows.calendars.holidays.index');
        Route::get('escalation-rules', [WorkflowEscalationRuleController::class, 'index'])->name('workflows.escalation-rules.index');
        Route::post('steps/{stepId}/decide', [WorkflowDecisionController::class, 'decide'])->name('workflows.decisions.decide');

        // Delegations
        Route::get('delegations', [WorkflowDelegationController::class, 'index'])->name('workflows.delegations.index');
        Route::post('delegations', [WorkflowDelegationController::class, 'store'])->name('workflows.delegations.store');
        Route::delete('delegations/{id}', [WorkflowDelegationController::class, 'destroy'])->name('workflows.delegations.destroy');
    });

// Definition management: everybody reads definitions above, only owners and admins write them (module key workflow_design;
// the workflow key stays open to every member, who may be an approver).
Route::prefix('api/v1/workflows/definitions')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:workflow_design'])
    ->whereUuid(['id', 'nodeId', 'transitionId'])
    ->group(function (): void {
        Route::post('/', [WorkflowDefinitionController::class, 'store'])->name('workflows.definitions.store');
        Route::put('{id}', [WorkflowDefinitionController::class, 'update'])->name('workflows.definitions.update');
        Route::patch('{id}', [WorkflowDefinitionController::class, 'update'])->name('workflows.definitions.patch');
        Route::delete('{id}', [WorkflowDefinitionController::class, 'destroy'])->name('workflows.definitions.destroy');
        Route::post('{id}/publish', [WorkflowDefinitionController::class, 'publish'])->name('workflows.definitions.publish');

        Route::post('{id}/nodes', [WorkflowDefinitionGraphController::class, 'storeNode'])->name('workflows.definitions.nodes.store');
        Route::put('{id}/nodes/{nodeId}', [WorkflowDefinitionGraphController::class, 'updateNode'])->name('workflows.definitions.nodes.update');
        Route::patch('{id}/nodes/{nodeId}', [WorkflowDefinitionGraphController::class, 'updateNode'])->name('workflows.definitions.nodes.patch');
        Route::delete('{id}/nodes/{nodeId}', [WorkflowDefinitionGraphController::class, 'destroyNode'])->name('workflows.definitions.nodes.destroy');

        Route::post('{id}/transitions', [WorkflowDefinitionGraphController::class, 'storeTransition'])->name('workflows.definitions.transitions.store');
        Route::put('{id}/transitions/{transitionId}', [WorkflowDefinitionGraphController::class, 'updateTransition'])->name('workflows.definitions.transitions.update');
        Route::patch('{id}/transitions/{transitionId}', [WorkflowDefinitionGraphController::class, 'updateTransition'])->name('workflows.definitions.transitions.patch');
        Route::delete('{id}/transitions/{transitionId}', [WorkflowDefinitionGraphController::class, 'destroyTransition'])->name('workflows.definitions.transitions.destroy');
    });

// Escalation rules change like definitions: owners and administrators only.
Route::prefix('api/v1/workflows/escalation-rules')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:workflow_design'])
    ->whereUuid('id')
    ->group(function (): void {
        Route::post('/', [WorkflowEscalationRuleController::class, 'store'])->name('workflows.escalation-rules.store');
        Route::put('{id}', [WorkflowEscalationRuleController::class, 'update'])->name('workflows.escalation-rules.update');
        Route::delete('{id}', [WorkflowEscalationRuleController::class, 'destroy'])->name('workflows.escalation-rules.destroy');
    });

// Matrices and calendars change like definitions: owners and administrators only.
Route::prefix('api/v1/workflows')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:workflow_design'])
    ->whereUuid(['id', 'holidayId'])
    ->group(function (): void {
        Route::post('matrices', [ApprovalMatrixController::class, 'store'])->name('workflows.matrices.store');
        Route::put('matrices/{id}', [ApprovalMatrixController::class, 'update'])->name('workflows.matrices.update');
        Route::delete('matrices/{id}', [ApprovalMatrixController::class, 'destroy'])->name('workflows.matrices.destroy');

        Route::post('calendars', [BusinessCalendarController::class, 'store'])->name('workflows.calendars.store');
        Route::put('calendars/{id}', [BusinessCalendarController::class, 'update'])->name('workflows.calendars.update');
        Route::post('calendars/{id}/holidays', [BusinessCalendarController::class, 'storeHoliday'])->name('workflows.calendars.holidays.store');
        Route::delete('calendars/{id}/holidays/{holidayId}', [BusinessCalendarController::class, 'destroyHoliday'])->name('workflows.calendars.holidays.destroy');
        Route::post('calendars/{id}/seed-holidays', [BusinessCalendarController::class, 'seedHolidays'])->name('workflows.calendars.holidays.seed');
    });

// One-click confirmation page of an approval link: the token in the URL is the credential and selects the tenant.
Route::prefix('api/v1/workflows/remote-approval')
    ->middleware(['api', 'throttle:30,1'])
    ->group(function (): void {
        Route::get('{token}', [RemoteApprovalController::class, 'show'])->name('workflows.remote-approval.show');
        Route::post('{token}/confirm', [RemoteApprovalController::class, 'confirm'])->name('workflows.remote-approval.confirm');
    });

// Remote Action Token Endpoint (Signed single-use URL without user session requirement)
Route::post('api/v1/workflows/remote-approval', [RemoteApprovalController::class, 'consume'])
    ->middleware(['api', 'signed'])
    ->name('workflows.remote-approval');
