<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Budgeting\Http\Controllers\ApprovalController;
use Modules\Budgeting\Http\Controllers\DashboardController;
use Modules\Budgeting\Http\Controllers\DriverController;
use Modules\Budgeting\Http\Controllers\LineController;
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
    });
