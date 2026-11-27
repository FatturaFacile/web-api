<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Observability\Http\Controllers\ConnectorController;
use Modules\Observability\Http\Controllers\MetricsController;
use Modules\Observability\Http\Controllers\ProfilerController;
use Modules\Observability\Http\Middleware\AuthenticateMetricsScrape;

/*
|--------------------------------------------------------------------------
| Enterprise observability (Mod 202 / Issue #448)
|--------------------------------------------------------------------------
*/

// Prometheus scrape endpoint: bearer token and/or allowed addresses, never a user session.
Route::get('metrics', MetricsController::class)
    ->middleware(['throttle:120,1', AuthenticateMetricsScrape::class])
    ->name('observability.metrics');

// Performance profiler, slow query inspector and connector administration: platform administrators only (no tenant context).
Route::prefix('api/v1/admin/observability')
    ->middleware(['api', 'auth:sanctum', 'platform.admin', 'locale'])
    ->name('admin.observability.')
    ->group(function (): void {
        Route::get('profiler/slow-queries', [ProfilerController::class, 'slowQueries'])->name('profiler.slow-queries');
        Route::get('profiler/slow-endpoints', [ProfilerController::class, 'slowEndpoints'])->name('profiler.slow-endpoints');
        Route::get('profiler/statements', [ProfilerController::class, 'statements'])->name('profiler.statements');
        Route::get('profiler/noisy-neighbors', [ProfilerController::class, 'noisyNeighbors'])->name('profiler.noisy-neighbors');
        Route::get('profiler/settings', [ProfilerController::class, 'showSettings'])->name('profiler.settings.show');
        Route::put('profiler/settings', [ProfilerController::class, 'updateSettings'])->name('profiler.settings.update');

        Route::get('connectors', [ConnectorController::class, 'index'])->name('connectors.index');
        Route::post('connectors/probe', [ConnectorController::class, 'probe'])->name('connectors.probe');
        Route::get('connectors/{key}/history', [ConnectorController::class, 'history'])->where('key', '[a-z_]+')->name('connectors.history');
        Route::put('connectors/{key}/maintenance', [ConnectorController::class, 'maintenance'])->where('key', '[a-z_]+')->name('connectors.maintenance');
    });

// Tenant-facing status of the institutional connectors (banner and status page): any member of the active company.
Route::prefix('api/v1/system')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])
    ->group(function (): void {
        Route::get('connector-status', [ConnectorController::class, 'summary'])->name('system.connector-status');
    });
