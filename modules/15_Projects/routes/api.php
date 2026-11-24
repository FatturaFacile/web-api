<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Projects\Http\Controllers\ProjectBillingController;
use Modules\Projects\Http\Controllers\ProjectBudgetController;
use Modules\Projects\Http\Controllers\ProjectChangeOrderController;
use Modules\Projects\Http\Controllers\ProjectContractBillingController;
use Modules\Projects\Http\Controllers\ProjectController;
use Modules\Projects\Http\Controllers\ProjectCostEntryController;
use Modules\Projects\Http\Controllers\ProjectEvmController;
use Modules\Projects\Http\Controllers\ProjectExpenseController;
use Modules\Projects\Http\Controllers\ProjectForecastController;
use Modules\Projects\Http\Controllers\ProjectMilestoneController;
use Modules\Projects\Http\Controllers\ProjectPortfolioController;
use Modules\Projects\Http\Controllers\ProjectResourceController;
use Modules\Projects\Http\Controllers\ProjectRevenueRecognitionController;
use Modules\Projects\Http\Controllers\ProjectTaskController;
use Modules\Projects\Http\Controllers\ProjectTemplateController;
use Modules\Projects\Http\Controllers\ProjectTimesheetController;
use Modules\Projects\Http\Controllers\ProjectWbsBaselineController;
use Modules\Projects\Http\Controllers\ProjectWbsController;
use Modules\Projects\Http\Controllers\ProjectWbsDependencyController;

/*
 * Projects (commesse). module.access:projects lets every member read and the write roles of config/permissions.php
 * write; the policies narrow management actions (approvals, budgets, billing, recognition, closing) to the managers.
 */
Route::prefix('api/v1/projects')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:projects'])
    ->group(function (): void {
        // Company-wide resources: capacity, people and rate cards.
        Route::get('/resource-capacity', [ProjectResourceController::class, 'capacity'])->name('projects.resource-capacity');
        Route::get('/resources', [ProjectResourceController::class, 'index'])->name('projects.resources.index');
        Route::put('/resources/{userId}', [ProjectResourceController::class, 'upsert'])->whereUuid('userId')->name('projects.resources.upsert');
        Route::get('/rate-cards', [ProjectResourceController::class, 'rateCards'])->name('projects.rate-cards.index');
        Route::post('/rate-cards', [ProjectResourceController::class, 'storeRateCard'])->name('projects.rate-cards.store');
        Route::delete('/rate-cards/{rateCardId}', [ProjectResourceController::class, 'destroyRateCard'])->whereUuid('rateCardId')->name('projects.rate-cards.destroy');

        // Records addressed by their own identifier.
        Route::put('/tasks/{taskId}', [ProjectTaskController::class, 'update'])->whereUuid('taskId')->name('projects.tasks.update');
        Route::delete('/tasks/{taskId}', [ProjectTaskController::class, 'destroy'])->whereUuid('taskId')->name('projects.tasks.destroy');
        Route::post('/tasks/{taskId}/dependencies', [ProjectTaskController::class, 'addDependency'])->whereUuid('taskId')->name('projects.tasks.dependencies.store');
        Route::post('/tasks/{taskId}/assignments', [ProjectTaskController::class, 'assign'])->whereUuid('taskId')->name('projects.tasks.assignments.store');
        Route::delete('/task-dependencies/{dependencyId}', [ProjectTaskController::class, 'removeDependency'])->whereUuid('dependencyId')->name('projects.task-dependencies.destroy');
        Route::delete('/task-assignments/{assignmentId}', [ProjectTaskController::class, 'unassign'])->whereUuid('assignmentId')->name('projects.task-assignments.destroy');
        Route::put('/milestones/{milestoneId}', [ProjectMilestoneController::class, 'update'])->whereUuid('milestoneId')->name('projects.milestones.update');
        Route::post('/milestones/{milestoneId}/complete', [ProjectMilestoneController::class, 'complete'])->whereUuid('milestoneId')->name('projects.milestones.complete');
        Route::put('/timesheets/{timesheetId}', [ProjectTimesheetController::class, 'update'])->whereUuid('timesheetId')->name('projects.timesheets.update');
        Route::delete('/timesheets/{timesheetId}', [ProjectTimesheetController::class, 'destroy'])->whereUuid('timesheetId')->name('projects.timesheets.destroy');
        Route::post('/timesheets/{timesheetId}/submit', [ProjectTimesheetController::class, 'submit'])->whereUuid('timesheetId')->name('projects.timesheets.submit');
        Route::post('/timesheets/{timesheetId}/approve', [ProjectTimesheetController::class, 'approve'])->whereUuid('timesheetId')->name('projects.timesheets.approve');
        Route::post('/timesheets/{timesheetId}/reject', [ProjectTimesheetController::class, 'reject'])->whereUuid('timesheetId')->name('projects.timesheets.reject');
        Route::post('/expenses/{expenseId}/approve', [ProjectExpenseController::class, 'approve'])->whereUuid('expenseId')->name('projects.expenses.approve');
        Route::post('/expenses/{expenseId}/reject', [ProjectExpenseController::class, 'reject'])->whereUuid('expenseId')->name('projects.expenses.reject');
        Route::post('/change-orders/{orderId}/approve', [ProjectChangeOrderController::class, 'approve'])->whereUuid('orderId')->name('projects.change-orders.approve');
        Route::post('/change-orders/{orderId}/reject', [ProjectChangeOrderController::class, 'reject'])->whereUuid('orderId')->name('projects.change-orders.reject');

        // Profitability and earned value (Mod 082): company-wide S-curve and overhead allocation.
        Route::get('/evm/scurve', [ProjectEvmController::class, 'scurve'])->name('projects.evm.scurve');
        Route::post('/evm/overhead-allocations', [ProjectEvmController::class, 'allocateOverhead'])->name('projects.evm.overhead-allocations');

        // Portfolio overview and creation from a template (Mod 078).
        Route::get('/portfolio', [ProjectPortfolioController::class, 'portfolio'])->name('projects.portfolio');
        Route::post('/from-template', [ProjectPortfolioController::class, 'fromTemplate'])->name('projects.from-template');
        Route::get('/templates', [ProjectTemplateController::class, 'index'])->name('projects.templates.index');
        Route::post('/templates', [ProjectTemplateController::class, 'store'])->name('projects.templates.store');
        Route::delete('/templates/{templateId}', [ProjectTemplateController::class, 'destroy'])->whereUuid('templateId')->name('projects.templates.destroy');

        // Project master data and lifecycle.
        Route::get('/', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('/', [ProjectController::class, 'store'])->name('projects.store');

        // Period-end WIP valuation across projects (Mod 081).
        Route::get('/wip-valuations', [ProjectContractBillingController::class, 'wipIndex'])->name('projects.wip-valuations.index');
        Route::post('/wip-valuations', [ProjectContractBillingController::class, 'wipRun'])->name('projects.wip-valuations.run');

        Route::where(['id' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'])->prefix('{id}')->group(function (): void {
            Route::get('/', [ProjectController::class, 'show'])->name('projects.show');
            Route::put('/', [ProjectController::class, 'update'])->name('projects.update');
            Route::delete('/', [ProjectController::class, 'destroy'])->name('projects.destroy');
            Route::post('/start', [ProjectController::class, 'start'])->name('projects.start');
            Route::post('/pause', [ProjectController::class, 'pause'])->name('projects.pause');
            Route::post('/resume', [ProjectController::class, 'resume'])->name('projects.resume');
            Route::post('/complete', [ProjectController::class, 'complete'])->name('projects.complete');
            Route::get('/closure-checks', [ProjectController::class, 'closureChecks'])->name('projects.closure-checks');
            Route::post('/close', [ProjectController::class, 'close'])->name('projects.close');
            Route::get('/financial-summary', [ProjectController::class, 'financialSummary'])->name('projects.financial-summary');
            Route::post('/recalculate-evm', [ProjectController::class, 'recalculateEVM'])->name('projects.recalculate-evm');
            Route::get('/evm-snapshots', [ProjectController::class, 'evmSnapshots'])->name('projects.evm-snapshots');
            Route::get('/gantt', [ProjectController::class, 'gantt'])->name('projects.gantt');
            Route::get('/critical-path', [ProjectController::class, 'criticalPath'])->name('projects.critical-path');
            Route::post('/schedule/baseline', [ProjectController::class, 'baselineSchedule'])->name('projects.schedule.baseline');
            Route::get('/audit-trail', [ProjectController::class, 'auditTrail'])->name('projects.audit-trail');

            // WBS and milestones.
            Route::get('/tasks', [ProjectTaskController::class, 'index'])->name('projects.tasks.index');
            Route::post('/tasks', [ProjectTaskController::class, 'store'])->name('projects.tasks.store');
            Route::get('/milestones', [ProjectMilestoneController::class, 'index'])->name('projects.milestones.index');
            Route::post('/milestones', [ProjectMilestoneController::class, 'store'])->name('projects.milestones.store');

            // Multilevel WBS, dependencies, critical path method, baselines and Kanban (Mod 078).
            Route::patch('/status', [ProjectPortfolioController::class, 'changeStatus'])->name('projects.status');
            Route::post('/wbs-nodes', [ProjectWbsController::class, 'store'])->name('projects.wbs-nodes.store');
            Route::patch('/wbs-nodes/reorder', [ProjectWbsController::class, 'reorder'])->name('projects.wbs-nodes.reorder');
            Route::put('/wbs-nodes/{nodeId}', [ProjectWbsController::class, 'update'])->whereUuid('nodeId')->name('projects.wbs-nodes.update');
            Route::delete('/wbs-nodes/{nodeId}', [ProjectWbsController::class, 'destroy'])->whereUuid('nodeId')->name('projects.wbs-nodes.destroy');
            Route::post('/dependencies', [ProjectWbsDependencyController::class, 'store'])->name('projects.dependencies.store');
            Route::put('/dependencies/{depId}', [ProjectWbsDependencyController::class, 'update'])->whereUuid('depId')->name('projects.dependencies.update');
            Route::delete('/dependencies/{depId}', [ProjectWbsDependencyController::class, 'destroy'])->whereUuid('depId')->name('projects.dependencies.destroy');
            Route::post('/cpm/recalculate', [ProjectWbsController::class, 'recalculate'])->name('projects.cpm.recalculate');
            Route::get('/baselines', [ProjectWbsBaselineController::class, 'index'])->name('projects.baselines.index');
            Route::post('/baselines', [ProjectWbsBaselineController::class, 'store'])->name('projects.baselines.store');
            Route::get('/baselines/{baselineId}/variance', [ProjectWbsBaselineController::class, 'variance'])->whereUuid('baselineId')->name('projects.baselines.variance');
            Route::get('/kanban', [ProjectWbsController::class, 'kanban'])->name('projects.kanban');

            // Budget by category, commitments and baselines.
            Route::get('/budget', [ProjectBudgetController::class, 'show'])->name('projects.budget.show');
            Route::post('/budget', [ProjectBudgetController::class, 'store'])->name('projects.budget.store');
            Route::post('/budget/baseline', [ProjectBudgetController::class, 'baseline'])->name('projects.budget.baseline');
            Route::post('/budget/approve', [ProjectBudgetController::class, 'approve'])->name('projects.budget.approve');
            Route::post('/budget/reject', [ProjectBudgetController::class, 'reject'])->name('projects.budget.reject');

            // Job costing, expenses, timesheets, change orders.
            Route::get('/costs', [ProjectCostEntryController::class, 'index'])->name('projects.costs.index');
            Route::post('/costs', [ProjectCostEntryController::class, 'store'])->name('projects.costs.store');
            Route::post('/expenses', [ProjectExpenseController::class, 'store'])->name('projects.expenses.store');
            Route::get('/timesheets', [ProjectTimesheetController::class, 'index'])->name('projects.timesheets.index');
            Route::post('/timesheets', [ProjectTimesheetController::class, 'store'])->name('projects.timesheets.store');
            Route::get('/change-orders', [ProjectChangeOrderController::class, 'index'])->name('projects.change-orders.index');
            Route::post('/change-orders', [ProjectChangeOrderController::class, 'store'])->name('projects.change-orders.store');

            // Billing, revenue recognition and reports.
            Route::post('/billing/milestones/{milestoneId}', [ProjectBillingController::class, 'milestone'])->whereUuid('milestoneId')->name('projects.billing.milestone');
            Route::post('/billing/time-and-materials', [ProjectBillingController::class, 'timeAndMaterials'])->name('projects.billing.time-and-materials');
            Route::get('/billing-events', [ProjectBillingController::class, 'events'])->name('projects.billing-events');
            Route::get('/revenue-recognitions', [ProjectRevenueRecognitionController::class, 'index'])->name('projects.revenue-recognitions.index');
            Route::post('/revenue-recognitions', [ProjectRevenueRecognitionController::class, 'store'])->name('projects.revenue-recognitions.store');
            Route::get('/forecast', [ProjectForecastController::class, 'show'])->name('projects.forecast.show');
            Route::get('/profitability', [ProjectForecastController::class, 'profitability'])->name('projects.profitability');

            // Profitability and earned value management (Mod 082).
            Route::post('/evm/compute', [ProjectEvmController::class, 'compute'])->name('projects.evm.compute');
            Route::get('/evm/snapshots', [ProjectEvmController::class, 'snapshots'])->name('projects.evm.snapshots');
            Route::put('/evm/etc', [ProjectEvmController::class, 'setManualEtc'])->name('projects.evm.etc');
            Route::get('/evm/wbs-breakdown', [ProjectEvmController::class, 'wbsBreakdown'])->name('projects.evm.wbs-breakdown');
            Route::get('/evm/waterfall', [ProjectEvmController::class, 'waterfall'])->name('projects.evm.waterfall');
            Route::get('/evm/ledger', [ProjectEvmController::class, 'ledger'])->name('projects.evm.ledger');

            // Project billing engine (Mod 081).
            Route::get('/billing-contract', [ProjectContractBillingController::class, 'showContract'])->name('projects.billing-contract.show');
            Route::put('/billing-contract', [ProjectContractBillingController::class, 'upsertContract'])->name('projects.billing-contract.upsert');
            Route::post('/billing-contract/status', [ProjectContractBillingController::class, 'changeContractStatus'])->name('projects.billing-contract.status');
            Route::get('/billing/unbilled-summary', [ProjectContractBillingController::class, 'unbilledSummary'])->name('projects.billing.unbilled-summary');
            Route::get('/billing/contract/time-and-materials', [ProjectContractBillingController::class, 'timeAndMaterialsPreview'])->name('projects.billing.contract.time-and-materials.preview');
            Route::post('/billing/contract/time-and-materials', [ProjectContractBillingController::class, 'timeAndMaterialsDraft'])->name('projects.billing.contract.time-and-materials.draft');
            Route::post('/billing/contract/milestones/{milestoneId}', [ProjectContractBillingController::class, 'milestone'])->whereUuid('milestoneId')->name('projects.billing.contract.milestone');
            Route::get('/sal-certificates', [ProjectContractBillingController::class, 'salIndex'])->name('projects.sal-certificates.index');
            Route::post('/sal-certificates', [ProjectContractBillingController::class, 'salStore'])->name('projects.sal-certificates.store');
            Route::post('/sal-certificates/{salId}/submit', [ProjectContractBillingController::class, 'salSubmit'])->whereUuid('salId')->name('projects.sal-certificates.submit');
            Route::post('/sal-certificates/{salId}/approve-and-invoice', [ProjectContractBillingController::class, 'salApproveAndInvoice'])->whereUuid('salId')->name('projects.sal-certificates.approve-and-invoice');
            Route::post('/sal-certificates/{salId}/cancel', [ProjectContractBillingController::class, 'salCancel'])->whereUuid('salId')->name('projects.sal-certificates.cancel');
            Route::get('/retention', [ProjectContractBillingController::class, 'retentionBalance'])->name('projects.retention.balance');
            Route::post('/retention/release', [ProjectContractBillingController::class, 'retentionRelease'])->name('projects.retention.release');
            Route::get('/retainer', [ProjectContractBillingController::class, 'retainerBalance'])->name('projects.retainer.balance');
            Route::post('/retainer/top-up', [ProjectContractBillingController::class, 'retainerTopUp'])->name('projects.retainer.top-up');
            Route::post('/retainer/consume', [ProjectContractBillingController::class, 'retainerConsume'])->name('projects.retainer.consume');
            Route::post('/wip-valuations', [ProjectContractBillingController::class, 'wipRunProject'])->name('projects.wip-valuations.run-project');
        });
    });
