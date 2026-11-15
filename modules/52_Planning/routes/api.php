<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Planning\Http\Controllers\AnalysisController;
use Modules\Planning\Http\Controllers\CapacityConstraintController;
use Modules\Planning\Http\Controllers\CycleController;
use Modules\Planning\Http\Controllers\DashboardController;
use Modules\Planning\Http\Controllers\PlanLineController;
use Modules\Planning\Http\Controllers\ResourceController;
use Modules\Planning\Http\Controllers\ScenarioController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Sales and operations planning. module.access:planning lets every member read (GET) and only the roles with write access
| of config/permissions.php change anything; the policy keeps the records inside the company and reserves the decisions
| of the S&OP cycle to approvers.
*/
Route::prefix('api/v1/planning')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:planning'])
    ->name('api.v1.planning.')
    ->where(['id' => $uuid])
    ->group(function (): void {
        Route::get('/dashboard', [DashboardController::class, 'summary'])->name('dashboard');

        Route::get('/scenarios', [ScenarioController::class, 'index'])->name('scenarios.index');
        Route::post('/scenarios', [ScenarioController::class, 'store'])->name('scenarios.store');
        Route::get('/scenarios/compare', [ScenarioController::class, 'compare'])->name('scenarios.compare');
        Route::get('/scenarios/{id}', [ScenarioController::class, 'show'])->name('scenarios.show');
        Route::put('/scenarios/{id}', [ScenarioController::class, 'update'])->name('scenarios.update');
        Route::delete('/scenarios/{id}', [ScenarioController::class, 'destroy'])->name('scenarios.destroy');
        Route::post('/scenarios/{id}/copy', [ScenarioController::class, 'copy'])->name('scenarios.copy');

        Route::post('/scenarios/{id}/submit', [CycleController::class, 'submit'])->name('scenarios.submit');
        Route::post('/scenarios/{id}/approve', [CycleController::class, 'approve'])->name('scenarios.approve');
        Route::post('/scenarios/{id}/reject', [CycleController::class, 'reject'])->name('scenarios.reject');
        Route::post('/scenarios/{id}/lock', [CycleController::class, 'lock'])->name('scenarios.lock');
        Route::post('/scenarios/{id}/publish', [CycleController::class, 'publish'])->name('scenarios.publish');
        Route::get('/publications', [CycleController::class, 'publications'])->name('publications.index');

        Route::get('/scenarios/{id}/lines', [PlanLineController::class, 'index'])->name('lines.index');
        Route::post('/scenarios/{id}/lines', [PlanLineController::class, 'store'])->name('lines.store');
        Route::post('/scenarios/{id}/import-forecasts', [PlanLineController::class, 'importForecasts'])->name('lines.import-forecasts');
        Route::put('/lines/{id}', [PlanLineController::class, 'update'])->name('lines.update');
        Route::delete('/lines/{id}', [PlanLineController::class, 'destroy'])->name('lines.destroy');

        Route::get('/scenarios/{id}/balance', [AnalysisController::class, 'balance'])->name('scenarios.balance');
        Route::get('/scenarios/{id}/capacity', [AnalysisController::class, 'capacity'])->name('scenarios.capacity');

        Route::get('/scenarios/{id}/constraints', [CapacityConstraintController::class, 'index'])->name('constraints.index');
        Route::post('/scenarios/{id}/constraints', [CapacityConstraintController::class, 'store'])->name('constraints.store');
        Route::delete('/constraints/{id}', [CapacityConstraintController::class, 'destroy'])->name('constraints.destroy');

        Route::get('/resources', [ResourceController::class, 'index'])->name('resources.index');
        Route::post('/resources', [ResourceController::class, 'store'])->name('resources.store');
        Route::get('/resources/{id}', [ResourceController::class, 'show'])->name('resources.show');
        Route::put('/resources/{id}', [ResourceController::class, 'update'])->name('resources.update');
        Route::delete('/resources/{id}', [ResourceController::class, 'destroy'])->name('resources.destroy');
        Route::post('/resources/{id}/rates', [ResourceController::class, 'storeRate'])->name('resources.rates.store');
        Route::put('/rates/{id}', [ResourceController::class, 'updateRate'])->name('rates.update');
        Route::delete('/rates/{id}', [ResourceController::class, 'destroyRate'])->name('rates.destroy');
    });
