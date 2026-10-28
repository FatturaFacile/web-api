<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Workflow\Http\Controllers\RemoteApprovalController;
use Modules\Workflow\Http\Controllers\WorkflowDecisionController;
use Modules\Workflow\Http\Controllers\WorkflowDefinitionController;
use Modules\Workflow\Http\Controllers\WorkflowDelegationController;
use Modules\Workflow\Http\Controllers\WorkflowInstanceController;

Route::prefix('api/v1/workflows')
    ->middleware(['api', 'auth:sanctum'])
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

        // Pending Decisions & Actions
        Route::get('my-pending', [WorkflowDecisionController::class, 'myPending'])->name('workflows.decisions.my-pending');
        Route::post('steps/{stepId}/decide', [WorkflowDecisionController::class, 'decide'])->name('workflows.decisions.decide');

        // Delegations
        Route::get('delegations', [WorkflowDelegationController::class, 'index'])->name('workflows.delegations.index');
        Route::post('delegations', [WorkflowDelegationController::class, 'store'])->name('workflows.delegations.store');
        Route::delete('delegations/{id}', [WorkflowDelegationController::class, 'destroy'])->name('workflows.delegations.destroy');
    });

// Remote Action Token Endpoint (Signed single-use URL without user session requirement)
Route::post('api/v1/workflows/remote-approval', [RemoteApprovalController::class, 'consume'])
    ->middleware(['api', 'signed'])
    ->name('workflows.remote-approval');
