<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Workflow\Http\Controllers\RemoteApprovalController;
use Modules\Workflow\Http\Controllers\WorkflowDecisionController;
use Modules\Workflow\Http\Controllers\WorkflowDefinitionController;
use Modules\Workflow\Http\Controllers\WorkflowDefinitionGraphController;
use Modules\Workflow\Http\Controllers\WorkflowDelegationController;
use Modules\Workflow\Http\Controllers\WorkflowEscalationRuleController;
use Modules\Workflow\Http\Controllers\WorkflowInboxController;
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

        // Pending Decisions & Actions
        Route::get('my-pending', [WorkflowDecisionController::class, 'myPending'])->name('workflows.decisions.my-pending');
        Route::get('my-tasks', [WorkflowInboxController::class, 'myTasks'])->name('workflows.inbox.my-tasks');
        Route::get('my-history', [WorkflowInboxController::class, 'myHistory'])->name('workflows.inbox.my-history');
        Route::get('my-requests', [WorkflowInboxController::class, 'myRequests'])->name('workflows.inbox.my-requests');
        Route::get('sla-report', [WorkflowInboxController::class, 'slaReport'])->name('workflows.inbox.sla-report');
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

// Remote Action Token Endpoint (Signed single-use URL without user session requirement)
Route::post('api/v1/workflows/remote-approval', [RemoteApprovalController::class, 'consume'])
    ->middleware(['api', 'signed'])
    ->name('workflows.remote-approval');
