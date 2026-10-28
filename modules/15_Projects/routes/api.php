<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Projects\Http\Controllers\ProjectBudgetController;
use Modules\Projects\Http\Controllers\ProjectChangeOrderController;
use Modules\Projects\Http\Controllers\ProjectController;
use Modules\Projects\Http\Controllers\ProjectCostEntryController;
use Modules\Projects\Http\Controllers\ProjectExpenseController;
use Modules\Projects\Http\Controllers\ProjectForecastController;
use Modules\Projects\Http\Controllers\ProjectRevenueRecognitionController;
use Modules\Projects\Http\Controllers\ProjectTimesheetController;

Route::prefix('api/v1/projects')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        // Project Lifecycle & Master
        Route::get('/', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('/', [ProjectController::class, 'store'])->name('projects.store');
        Route::get('/{id}', [ProjectController::class, 'show'])->name('projects.show');
        Route::put('/{id}', [ProjectController::class, 'update'])->name('projects.update');
        Route::delete('/{id}', [ProjectController::class, 'destroy'])->name('projects.destroy');

        Route::post('/{id}/start', [ProjectController::class, 'start'])->name('projects.start');
        Route::post('/{id}/pause', [ProjectController::class, 'pause'])->name('projects.pause');
        Route::post('/{id}/resume', [ProjectController::class, 'resume'])->name('projects.resume');
        Route::post('/{id}/close', [ProjectController::class, 'close'])->name('projects.close');
        Route::get('/{id}/financial-summary', [ProjectController::class, 'financialSummary'])->name('projects.financial-summary');
        Route::post('/{id}/recalculate-evm', [ProjectController::class, 'recalculateEVM'])->name('projects.recalculate-evm');
        Route::get('/{id}/gantt', [ProjectController::class, 'gantt'])->name('projects.gantt');

        // Multi-tier Budgeting & Baseline Snapshots
        Route::get('/{id}/budget', [ProjectBudgetController::class, 'show'])->name('projects.budget.show');
        Route::post('/{id}/budget', [ProjectBudgetController::class, 'store'])->name('projects.budget.store');
        Route::post('/{id}/budget/baseline', [ProjectBudgetController::class, 'baseline'])->name('projects.budget.baseline');
        Route::post('/{id}/budget/approve', [ProjectBudgetController::class, 'approve'])->name('projects.budget.approve');

        // Job Costing Accumulation & Rollup
        Route::get('/{id}/costs', [ProjectCostEntryController::class, 'index'])->name('projects.costs.index');
        Route::post('/{id}/costs', [ProjectCostEntryController::class, 'store'])->name('projects.costs.store');

        // Project Direct & Indirect Expenses
        Route::post('/{id}/expenses', [ProjectExpenseController::class, 'store'])->name('projects.expenses.store');
        Route::post('/expenses/{expenseId}/approve', [ProjectExpenseController::class, 'approve'])->name('projects.expenses.approve');

        // Financial & Cash Flow Forecasts
        Route::get('/{id}/forecast', [ProjectForecastController::class, 'show'])->name('projects.forecast.show');

        // Change Orders & Variations
        Route::get('/{id}/change-orders', [ProjectChangeOrderController::class, 'index'])->name('projects.change-orders.index');
        Route::post('/{id}/change-orders', [ProjectChangeOrderController::class, 'store'])->name('projects.change-orders.store');
        Route::post('/change-orders/{orderId}/approve', [ProjectChangeOrderController::class, 'approve'])->name('projects.change-orders.approve');

        // Timesheets & Labour Burden Posting
        Route::get('/{id}/timesheets', [ProjectTimesheetController::class, 'index'])->name('projects.timesheets.index');
        Route::post('/{id}/timesheets', [ProjectTimesheetController::class, 'store'])->name('projects.timesheets.store');
        Route::post('/timesheets/{timesheetId}/approve', [ProjectTimesheetController::class, 'approve'])->name('projects.timesheets.approve');

        // IFRS 15 / ASC 606 Revenue Recognition
        Route::get('/{id}/revenue-recognitions', [ProjectRevenueRecognitionController::class, 'index'])->name('projects.revenue-recognitions.index');
        Route::post('/{id}/revenue-recognitions', [ProjectRevenueRecognitionController::class, 'store'])->name('projects.revenue-recognitions.store');
    });
