<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\AnalyticsDss\Presentation\Http\Controllers\DssAdminController;
use Modules\AnalyticsDss\Presentation\Http\Controllers\ExecutiveDssController;

// bi.dss.view reads, bi.dss.simulate simulates, bi.dss.export generates the board pack, bi.dss.admin reconciles and rebuilds the mart
// (modules bi_dss, bi_dss_simulate, bi_dss_export and bi_dss_admin of config/permissions.php).
Route::prefix('api/v1/analytics/dss')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])
    ->group(function (): void {
        Route::middleware('module.access:bi_dss')->group(function (): void {
            Route::get('overview', [ExecutiveDssController::class, 'overview'])->name('analytics.dss.overview');
            Route::get('profitability-matrix', [ExecutiveDssController::class, 'profitabilityMatrix'])->name('analytics.dss.profitability-matrix');
            Route::get('variance-bridge', [ExecutiveDssController::class, 'varianceBridge'])->name('analytics.dss.variance-bridge');
            Route::get('drill-down', [ExecutiveDssController::class, 'drillDown'])->name('analytics.dss.drill-down');
            Route::get('legal-entities', [DssAdminController::class, 'legalEntities'])->name('analytics.dss.legal-entities');
        });

        Route::post('simulate', [ExecutiveDssController::class, 'simulate'])->middleware('module.access:bi_dss_simulate')->name('analytics.dss.simulate');
        Route::post('export-board-pack', [ExecutiveDssController::class, 'exportBoardPack'])->middleware(['module.access:bi_dss_export', 'throttle:20,1'])->name('analytics.dss.export-board-pack');

        Route::middleware('module.access:bi_dss_admin')->prefix('admin')->group(function (): void {
            Route::get('reconciliation', [DssAdminController::class, 'reconciliation'])->name('analytics.dss.admin.reconciliation');
            Route::post('consolidate', [DssAdminController::class, 'consolidate'])->name('analytics.dss.admin.consolidate');
        });
    });
