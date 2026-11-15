<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\BusinessIntelligence\Http\Controllers\CalculatedMeasureController;
use Modules\BusinessIntelligence\Http\Controllers\CatalogController;
use Modules\BusinessIntelligence\Http\Controllers\DashboardController;
use Modules\BusinessIntelligence\Http\Controllers\OverviewController;
use Modules\BusinessIntelligence\Http\Controllers\SavedQueryController;
use Modules\BusinessIntelligence\Http\Controllers\ShareController;
use Modules\BusinessIntelligence\Http\Controllers\SnapshotController;
use Modules\BusinessIntelligence\Http\Controllers\WidgetController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Dashboards, pivots, calculated measures, shares and snapshots on the semantic layer. module.access:business_intelligence
| lets every member read (GET) and only the roles with write access of config/permissions.php change anything; the policy
| decides who sees and edits each dashboard and saved query, and the report engine limits every query to the active company
| and to the datasets of the modules the caller can read.
*/
Route::prefix('api/v1/business-intelligence')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:business_intelligence'])
    ->name('api.v1.business-intelligence.')
    ->where(['id' => $uuid, 'dashboardId' => $uuid])
    ->group(function (): void {
        Route::get('/overview', [OverviewController::class, 'show'])->name('overview');

        Route::get('/datasets', [CatalogController::class, 'index'])->name('datasets.index');
        Route::get('/datasets/{dataset}', [CatalogController::class, 'show'])->where('dataset', '[a-z][a-z0-9_]*')->name('datasets.show');

        Route::get('/calculated-measures', [CalculatedMeasureController::class, 'index'])->name('calculated-measures.index');
        Route::post('/calculated-measures', [CalculatedMeasureController::class, 'store'])->name('calculated-measures.store');
        Route::get('/calculated-measures/{id}', [CalculatedMeasureController::class, 'show'])->name('calculated-measures.show');
        Route::put('/calculated-measures/{id}', [CalculatedMeasureController::class, 'update'])->name('calculated-measures.update');
        Route::delete('/calculated-measures/{id}', [CalculatedMeasureController::class, 'destroy'])->name('calculated-measures.destroy');

        Route::get('/saved-queries', [SavedQueryController::class, 'index'])->name('saved-queries.index');
        Route::post('/saved-queries', [SavedQueryController::class, 'store'])->name('saved-queries.store');
        Route::get('/saved-queries/{id}', [SavedQueryController::class, 'show'])->name('saved-queries.show');
        Route::put('/saved-queries/{id}', [SavedQueryController::class, 'update'])->name('saved-queries.update');
        Route::delete('/saved-queries/{id}', [SavedQueryController::class, 'destroy'])->name('saved-queries.destroy');
        Route::get('/saved-queries/{id}/run', [SavedQueryController::class, 'run'])->name('saved-queries.run');
        Route::post('/explorer/run', [SavedQueryController::class, 'explore'])->name('explorer.run');

        Route::get('/dashboards', [DashboardController::class, 'index'])->name('dashboards.index');
        Route::post('/dashboards', [DashboardController::class, 'store'])->name('dashboards.store');
        Route::get('/dashboards/{id}', [DashboardController::class, 'show'])->name('dashboards.show');
        Route::put('/dashboards/{id}', [DashboardController::class, 'update'])->name('dashboards.update');
        Route::delete('/dashboards/{id}', [DashboardController::class, 'destroy'])->name('dashboards.destroy');
        Route::put('/dashboards/{id}/layout', [DashboardController::class, 'layout'])->name('dashboards.layout');
        Route::get('/dashboards/{id}/data', [DashboardController::class, 'data'])->name('dashboards.data');

        Route::post('/dashboards/{dashboardId}/widgets', [WidgetController::class, 'store'])->name('widgets.store');
        Route::put('/widgets/{id}', [WidgetController::class, 'update'])->name('widgets.update');
        Route::delete('/widgets/{id}', [WidgetController::class, 'destroy'])->name('widgets.destroy');
        Route::get('/widgets/{id}/data', [WidgetController::class, 'data'])->name('widgets.data');

        Route::get('/shares', [ShareController::class, 'index'])->name('shares.index');
        Route::post('/shares', [ShareController::class, 'store'])->name('shares.store');
        Route::delete('/shares/{id}', [ShareController::class, 'destroy'])->name('shares.destroy');

        Route::get('/snapshots', [SnapshotController::class, 'index'])->name('snapshots.index');
        Route::post('/snapshots', [SnapshotController::class, 'store'])->name('snapshots.store');
        Route::get('/snapshots/{id}', [SnapshotController::class, 'show'])->name('snapshots.show');
        Route::get('/snapshots/{id}/compare', [SnapshotController::class, 'compare'])->name('snapshots.compare');
        Route::delete('/snapshots/{id}', [SnapshotController::class, 'destroy'])->name('snapshots.destroy');
    });
