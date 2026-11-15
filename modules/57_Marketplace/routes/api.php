<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Marketplace\Http\Controllers\ActivityController;
use Modules\Marketplace\Http\Controllers\CatalogController;
use Modules\Marketplace\Http\Controllers\InstallationController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Connector marketplace. module.access:marketplace limits it to owners and administrators (an installation holds credentials of
| external systems); the policy keeps the records inside the company. Credentials are write-only: no endpoint returns them.
*/
Route::prefix('api/v1/marketplace')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:marketplace'])
    ->name('api.v1.marketplace.')
    ->where(['id' => $uuid])
    ->group(function (): void {
        Route::get('/overview', [ActivityController::class, 'overview'])->name('overview');

        Route::get('/connectors', [CatalogController::class, 'index'])->name('connectors.index');
        Route::get('/connectors/{key}', [CatalogController::class, 'show'])->where('key', '[a-z][a-z0-9-]*')->name('connectors.show');

        Route::get('/installations', [InstallationController::class, 'index'])->name('installations.index');
        Route::post('/installations', [InstallationController::class, 'store'])->name('installations.store');
        Route::get('/installations/{id}', [InstallationController::class, 'show'])->name('installations.show');
        Route::put('/installations/{id}/configuration', [InstallationController::class, 'configure'])->name('installations.configure');
        Route::post('/installations/{id}/enable', [InstallationController::class, 'enable'])->name('installations.enable');
        Route::post('/installations/{id}/disable', [InstallationController::class, 'disable'])->name('installations.disable');
        Route::post('/installations/{id}/upgrade', [InstallationController::class, 'upgrade'])->name('installations.upgrade');
        Route::delete('/installations/{id}', [InstallationController::class, 'destroy'])->name('installations.destroy');

        Route::get('/installations/{id}/health-checks', [ActivityController::class, 'healthChecks'])->name('health-checks.index');
        Route::post('/installations/{id}/health-checks', [ActivityController::class, 'runHealthCheck'])->name('health-checks.store');
        Route::get('/installations/{id}/sync-runs', [ActivityController::class, 'installationRuns'])->name('installations.sync-runs.index');
        Route::post('/installations/{id}/sync-runs', [ActivityController::class, 'runSync'])->name('installations.sync-runs.store');
        Route::get('/sync-runs', [ActivityController::class, 'runs'])->name('sync-runs.index');
    });
