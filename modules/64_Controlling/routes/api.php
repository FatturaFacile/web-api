<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Controlling\Http\Controllers\AllocationController;
use Modules\Controlling\Http\Controllers\AnalyticalLedgerController;
use Modules\Controlling\Http\Controllers\BudgetController;
use Modules\Controlling\Http\Controllers\CostCenterController;
use Modules\Controlling\Http\Controllers\FinancialKpiController;
use Modules\Controlling\Http\Controllers\JobController;
use Modules\Controlling\Http\Controllers\MarginController;
use Modules\Controlling\Http\Controllers\VarianceController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Controlling and corporate performance management. module.access:controlling lets every finance, sales and production
| member read (GET) and only the roles with write access of config/permissions.php change anything; the policy keeps the
| records inside the company and reserves allocation runs and budget approvals.
*/
Route::middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:controlling'])
    ->where(['id' => $uuid, 'runId' => $uuid])
    ->group(function (): void {
        Route::prefix('api/v1/controlling')->name('api.v1.controlling.')->group(function (): void {
            Route::get('/cost-centers', [CostCenterController::class, 'index'])->name('cost-centers.index');
            Route::post('/cost-centers', [CostCenterController::class, 'store'])->name('cost-centers.store');
            Route::get('/cost-centers/{id}', [CostCenterController::class, 'show'])->name('cost-centers.show');
            Route::put('/cost-centers/{id}', [CostCenterController::class, 'update'])->name('cost-centers.update');
            Route::delete('/cost-centers/{id}', [CostCenterController::class, 'destroy'])->name('cost-centers.destroy');

            Route::get('/jobs', [JobController::class, 'index'])->name('jobs.index');
            Route::post('/jobs', [JobController::class, 'store'])->name('jobs.store');
            Route::get('/jobs/{id}', [JobController::class, 'show'])->name('jobs.show');
            Route::put('/jobs/{id}', [JobController::class, 'update'])->name('jobs.update');
            Route::delete('/jobs/{id}', [JobController::class, 'destroy'])->name('jobs.destroy');
            Route::get('/jobs/{id}/pnl', [JobController::class, 'pnl'])->name('jobs.pnl');

            Route::get('/analytical-ledger', [AnalyticalLedgerController::class, 'index'])->name('ledger.index');
            Route::post('/analytical-ledger', [AnalyticalLedgerController::class, 'store'])->name('ledger.store');

            Route::get('/allocation-rules', [AllocationController::class, 'rules'])->name('allocation-rules.index');
            Route::post('/allocation-rules', [AllocationController::class, 'storeRule'])->name('allocation-rules.store');
            Route::put('/allocation-rules/{id}', [AllocationController::class, 'updateRule'])->name('allocation-rules.update');
            Route::delete('/allocation-rules/{id}', [AllocationController::class, 'destroyRule'])->name('allocation-rules.destroy');
            Route::get('/allocations', [AllocationController::class, 'runs'])->name('allocations.index');
            Route::post('/allocations/preview', [AllocationController::class, 'preview'])->name('allocations.preview');
            Route::post('/allocations/execute', [AllocationController::class, 'execute'])->name('allocations.execute');
            Route::post('/allocations/{runId}/reverse', [AllocationController::class, 'reverse'])->name('allocations.reverse');
        });

        Route::prefix('api/v1/analytics')->name('api.v1.analytics.')->group(function (): void {
            Route::get('/margins/summary', [MarginController::class, 'summary'])->name('margins.summary');
            Route::get('/margins/explore', [MarginController::class, 'explore'])->name('margins.explore');
            Route::get('/margins/matrix', [MarginController::class, 'matrix'])->name('margins.matrix');
            Route::get('/margins/pareto', [MarginController::class, 'pareto'])->name('margins.pareto');
            Route::get('/margins/export', [MarginController::class, 'export'])->name('margins.export');
            Route::get('/financial-kpis', [FinancialKpiController::class, 'index'])->name('financial-kpis');
            Route::post('/financial-kpis/snapshots', [FinancialKpiController::class, 'snapshot'])->name('financial-kpis.snapshots');
            Route::get('/variance', [VarianceController::class, 'variance'])->name('variance');
            Route::get('/variance/waterfall', [VarianceController::class, 'waterfall'])->name('variance.waterfall');
        });

        Route::prefix('api/v1/budgets')->name('api.v1.budgets.')->group(function (): void {
            Route::get('/', [BudgetController::class, 'index'])->name('index');
            Route::post('/', [BudgetController::class, 'store'])->name('store');
            Route::post('/seed-from-prior-year', [BudgetController::class, 'seed'])->name('seed');
            Route::get('/{id}', [BudgetController::class, 'show'])->name('show');
            Route::put('/{id}', [BudgetController::class, 'update'])->name('update');
            Route::delete('/{id}', [BudgetController::class, 'destroy'])->name('destroy');
            Route::post('/{id}/clone', [BudgetController::class, 'clone'])->name('clone');
            Route::post('/{id}/submit', [BudgetController::class, 'submit'])->name('submit');
            Route::post('/{id}/approve', [BudgetController::class, 'approve'])->name('approve');
            Route::post('/{id}/lock', [BudgetController::class, 'lock'])->name('lock');
            Route::put('/{id}/lines/bulk', [BudgetController::class, 'bulkLines'])->name('lines.bulk');
            Route::post('/{id}/generate-rolling-forecast', [BudgetController::class, 'rollingForecast'])->name('rolling-forecast');
        });
    });
