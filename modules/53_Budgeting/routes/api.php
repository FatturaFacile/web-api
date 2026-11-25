<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Budgeting\Http\Controllers\ActualVolumeController;
use Modules\Budgeting\Http\Controllers\ApprovalController;
use Modules\Budgeting\Http\Controllers\DashboardController;
use Modules\Budgeting\Http\Controllers\DriverController;
use Modules\Budgeting\Http\Controllers\ExchangeController;
use Modules\Budgeting\Http\Controllers\GridController;
use Modules\Budgeting\Http\Controllers\LineController;
use Modules\Budgeting\Http\Controllers\OverrideController;
use Modules\Budgeting\Http\Controllers\SpendControlController;
use Modules\Budgeting\Http\Controllers\SpendRuleController;
use Modules\Budgeting\Http\Controllers\VarianceController;
use Modules\Budgeting\Http\Controllers\VersionController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Planning and budgeting. module.access:budgeting lets every member read (GET) and only the roles with write access of
| config/permissions.php change anything; the policy keeps the records inside the company and reserves the approval
| decisions to approvers.
*/
Route::prefix('api/v1/budgeting')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:budgeting'])
    ->name('api.v1.budgeting.')
    ->where(['id' => $uuid])
    ->group(function (): void {
        Route::get('/dashboard', [DashboardController::class, 'summary'])->name('dashboard');

        Route::get('/versions', [VersionController::class, 'index'])->name('versions.index');
        Route::post('/versions', [VersionController::class, 'store'])->name('versions.store');
        Route::get('/versions/{id}', [VersionController::class, 'show'])->name('versions.show');
        Route::put('/versions/{id}', [VersionController::class, 'update'])->name('versions.update');
        Route::delete('/versions/{id}', [VersionController::class, 'destroy'])->name('versions.destroy');
        Route::post('/versions/{id}/copy', [VersionController::class, 'copy'])->name('versions.copy');
        Route::post('/versions/{id}/reforecast', [VersionController::class, 'reforecast'])->name('versions.reforecast');

        Route::post('/versions/{id}/submit', [ApprovalController::class, 'submit'])->name('versions.submit');
        Route::post('/versions/{id}/approve', [ApprovalController::class, 'approve'])->name('versions.approve');
        Route::post('/versions/{id}/reject', [ApprovalController::class, 'reject'])->name('versions.reject');
        Route::post('/versions/{id}/lock', [ApprovalController::class, 'lock'])->name('versions.lock');

        Route::get('/versions/{id}/lines', [LineController::class, 'index'])->name('lines.index');
        Route::post('/versions/{id}/lines', [LineController::class, 'store'])->name('lines.store');
        Route::post('/versions/{id}/spread', [LineController::class, 'spread'])->name('lines.spread');
        Route::put('/lines/{id}', [LineController::class, 'update'])->name('lines.update');
        Route::delete('/lines/{id}', [LineController::class, 'destroy'])->name('lines.destroy');

        Route::get('/versions/{id}/drivers', [DriverController::class, 'index'])->name('drivers.index');
        Route::post('/versions/{id}/drivers', [DriverController::class, 'store'])->name('drivers.store');
        Route::post('/versions/{id}/generate-drivers', [DriverController::class, 'generateAll'])->name('drivers.generate-all');
        Route::put('/drivers/{id}', [DriverController::class, 'update'])->name('drivers.update');
        Route::delete('/drivers/{id}', [DriverController::class, 'destroy'])->name('drivers.destroy');
        Route::post('/drivers/{id}/generate', [DriverController::class, 'generate'])->name('drivers.generate');

        Route::get('/versions/{id}/variance', [VarianceController::class, 'show'])->name('versions.variance');
        Route::get('/versions/{id}/compare', [VarianceController::class, 'compare'])->name('versions.compare');

        Route::get('/versions/{id}/variance/decomposition', [VarianceController::class, 'decomposition'])->name('versions.variance.decomposition');

        Route::get('/versions/{id}/grid', [GridController::class, 'show'])->name('versions.grid');
        Route::put('/versions/{id}/grid', [GridController::class, 'save'])->name('versions.grid.save');
        Route::post('/versions/{id}/revise', [GridController::class, 'revise'])->name('versions.revise');
        Route::post('/versions/{id}/scenario', [GridController::class, 'scenario'])->name('versions.scenario');
        Route::post('/versions/{id}/transfer', [GridController::class, 'transfer'])->name('versions.transfer');
        Route::post('/versions/{id}/roll-forward', [GridController::class, 'rollForward'])->name('versions.roll-forward');
        Route::get('/versions/{id}/audit', [GridController::class, 'audit'])->name('versions.audit');

        Route::get('/versions/{id}/import-template', [ExchangeController::class, 'template'])->name('versions.import-template');
        Route::post('/versions/{id}/import', [ExchangeController::class, 'import'])->name('versions.import');
        Route::get('/versions/{id}/imports', [ExchangeController::class, 'imports'])->name('versions.imports');
        Route::get('/versions/{id}/executive-pack', [ExchangeController::class, 'executivePack'])->name('versions.executive-pack');

        Route::get('/actual-volumes', [ActualVolumeController::class, 'index'])->name('actual-volumes.index');
        Route::post('/actual-volumes', [ActualVolumeController::class, 'store'])->name('actual-volumes.store');
        Route::delete('/actual-volumes/{id}', [ActualVolumeController::class, 'destroy'])->name('actual-volumes.destroy');

        Route::prefix('spend-control')->name('spend-control.')->group(function (): void {
            Route::get('/availability', [SpendControlController::class, 'availability'])->name('availability');
            Route::post('/check', [SpendControlController::class, 'check'])->name('check');
            Route::get('/commitments', [SpendControlController::class, 'commitments'])->name('commitments');
            Route::get('/checks', [SpendControlController::class, 'checks'])->name('checks');
            Route::get('/settings', [SpendControlController::class, 'showSettings'])->name('settings.show');
            Route::put('/settings', [SpendControlController::class, 'updateSettings'])->name('settings.update');

            Route::get('/rules', [SpendRuleController::class, 'index'])->name('rules.index');
            Route::post('/rules', [SpendRuleController::class, 'store'])->name('rules.store');
            Route::put('/rules/{id}', [SpendRuleController::class, 'update'])->name('rules.update');
            Route::delete('/rules/{id}', [SpendRuleController::class, 'destroy'])->name('rules.destroy');

            Route::get('/overrides', [OverrideController::class, 'index'])->name('overrides.index');
            Route::post('/overrides', [OverrideController::class, 'store'])->name('overrides.store');
            Route::get('/overrides/{id}', [OverrideController::class, 'show'])->name('overrides.show');
            Route::post('/overrides/{id}/approve', [OverrideController::class, 'approve'])->name('overrides.approve');
            Route::post('/overrides/{id}/reject', [OverrideController::class, 'reject'])->name('overrides.reject');
            Route::post('/overrides/{id}/cancel', [OverrideController::class, 'cancel'])->name('overrides.cancel');
            Route::get('/overrides/{id}/history', [OverrideController::class, 'history'])->name('overrides.history');
        });
    });
