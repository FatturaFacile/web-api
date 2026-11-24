<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\AnalyticalAccounting\Http\Controllers\CenterController;
use Modules\AnalyticalAccounting\Http\Controllers\DerivationRuleController;
use Modules\AnalyticalAccounting\Http\Controllers\DriverController;
use Modules\AnalyticalAccounting\Http\Controllers\EntryController;
use Modules\AnalyticalAccounting\Http\Controllers\ReportController;
use Modules\AnalyticalAccounting\Http\Controllers\RunController;
use Modules\AnalyticalAccounting\Http\Controllers\ScheduleController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Industrial and analytical cost accounting (Mod 018). module.access:analytical_accounting lets finance, sales management
| and production read (GET) and only owners, administrators and accountants write; the policy keeps every record inside
| the company and reserves running apportionment cycles to analytical.apportionment.run.
*/
Route::middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:analytical_accounting'])
    ->where(['id' => $uuid])
    ->prefix('api/v1/analytical')->name('api.v1.analytical.')
    ->group(function (): void {
        Route::get('/centers', [CenterController::class, 'index'])->name('centers.index');
        Route::get('/centers/tree', [CenterController::class, 'tree'])->name('centers.tree');
        Route::get('/centers/flat', [CenterController::class, 'flat'])->name('centers.flat');
        Route::post('/centers', [CenterController::class, 'store'])->name('centers.store');
        Route::get('/centers/{id}', [CenterController::class, 'show'])->name('centers.show');
        Route::put('/centers/{id}', [CenterController::class, 'update'])->name('centers.update');
        Route::delete('/centers/{id}', [CenterController::class, 'destroy'])->name('centers.destroy');
        Route::post('/centers/{id}/deactivate', [CenterController::class, 'deactivate'])->name('centers.deactivate');
        Route::get('/centers/{id}/ancestors', [CenterController::class, 'ancestors'])->name('centers.ancestors');
        Route::get('/centers/{id}/descendants', [CenterController::class, 'descendants'])->name('centers.descendants');

        Route::get('/derivation-rules', [DerivationRuleController::class, 'index'])->name('rules.index');
        Route::post('/derivation-rules', [DerivationRuleController::class, 'store'])->name('rules.store');
        Route::post('/derivation-rules/test', [DerivationRuleController::class, 'test'])->name('rules.test');
        Route::get('/derivation-rules/{id}', [DerivationRuleController::class, 'show'])->name('rules.show');
        Route::put('/derivation-rules/{id}', [DerivationRuleController::class, 'update'])->name('rules.update');
        Route::delete('/derivation-rules/{id}', [DerivationRuleController::class, 'destroy'])->name('rules.destroy');

        Route::get('/drivers', [DriverController::class, 'index'])->name('drivers.index');
        Route::post('/drivers', [DriverController::class, 'store'])->name('drivers.store');
        Route::put('/drivers/{id}', [DriverController::class, 'update'])->name('drivers.update');
        Route::delete('/drivers/{id}', [DriverController::class, 'destroy'])->name('drivers.destroy');
        Route::get('/drivers/{id}/values', [DriverController::class, 'values'])->name('drivers.values.index');
        Route::put('/drivers/{id}/values', [DriverController::class, 'storeValues'])->name('drivers.values.store');

        Route::get('/entries', [EntryController::class, 'index'])->name('entries.index');
        Route::post('/entries', [EntryController::class, 'store'])->name('entries.store');
        Route::get('/entries/{id}', [EntryController::class, 'show'])->name('entries.show');
        Route::post('/entries/{id}/reverse', [EntryController::class, 'reverse'])->name('entries.reverse');

        Route::get('/schedules', [ScheduleController::class, 'index'])->name('schedules.index');
        Route::post('/schedules', [ScheduleController::class, 'store'])->name('schedules.store');
        Route::get('/schedules/{id}', [ScheduleController::class, 'show'])->name('schedules.show');
        Route::put('/schedules/{id}', [ScheduleController::class, 'update'])->name('schedules.update');
        Route::delete('/schedules/{id}', [ScheduleController::class, 'destroy'])->name('schedules.destroy');

        Route::get('/runs', [RunController::class, 'index'])->name('runs.index');
        Route::post('/runs/preview', [RunController::class, 'preview'])->name('runs.preview');
        Route::get('/runs/{id}', [RunController::class, 'show'])->name('runs.show');
        Route::post('/runs/{id}/consolidate', [RunController::class, 'consolidate'])->name('runs.consolidate');
        Route::post('/runs/{id}/reverse', [RunController::class, 'reverse'])->name('runs.reverse');

        Route::get('/reports/contribution-margin', [ReportController::class, 'contributionMargin'])->name('reports.contribution-margin');
        Route::get('/reports/direct-vs-apportioned', [ReportController::class, 'directVsApportioned'])->name('reports.direct-vs-apportioned');
    });
