<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\LiquidityPlanning\Http\Controllers\AlertController;
use Modules\LiquidityPlanning\Http\Controllers\ManualItemController;
use Modules\LiquidityPlanning\Http\Controllers\OverviewController;
use Modules\LiquidityPlanning\Http\Controllers\ScenarioController;
use Modules\LiquidityPlanning\Http\Controllers\SettingsController;

// liquidity_planning: the finance roles read the forecast, the indicators and the alerts; the accountants and the administrators
// change the settings, the manual items and the scenarios and evaluate the alerts. The what-if simulation and the exports are
// GET requests that save nothing.
Route::prefix('api/v1/liquidity-planning')->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])->group(function (): void {
    Route::middleware('module.access:liquidity_planning,read')->group(function (): void {
        Route::get('overview', [OverviewController::class, 'overview'])->name('liquidity-planning.overview');
        Route::get('report', [OverviewController::class, 'report'])->name('liquidity-planning.report');
        Route::get('report/pdf', [OverviewController::class, 'pdf'])->name('liquidity-planning.report.pdf');
        Route::get('export/xlsx', [OverviewController::class, 'xlsx'])->name('liquidity-planning.export.xlsx');
        Route::get('settings', [SettingsController::class, 'show'])->name('liquidity-planning.settings.show');
        Route::get('manual-items', [ManualItemController::class, 'index'])->name('liquidity-planning.manual-items.index');
        Route::get('scenarios/presets', [ScenarioController::class, 'presets'])->name('liquidity-planning.scenarios.presets');
        Route::get('scenarios', [ScenarioController::class, 'index'])->name('liquidity-planning.scenarios.index');
        Route::get('alerts', [AlertController::class, 'index'])->name('liquidity-planning.alerts.index');
    });

    Route::middleware('module.access:liquidity_planning,write')->group(function (): void {
        Route::put('settings', [SettingsController::class, 'update'])->name('liquidity-planning.settings.update');
        Route::post('manual-items', [ManualItemController::class, 'store'])->name('liquidity-planning.manual-items.store');
        Route::put('manual-items/{id}', [ManualItemController::class, 'update'])->whereUuid('id')->name('liquidity-planning.manual-items.update');
        Route::delete('manual-items/{id}', [ManualItemController::class, 'destroy'])->whereUuid('id')->name('liquidity-planning.manual-items.destroy');
        Route::post('scenarios', [ScenarioController::class, 'store'])->name('liquidity-planning.scenarios.store');
        Route::put('scenarios/{id}', [ScenarioController::class, 'update'])->whereUuid('id')->name('liquidity-planning.scenarios.update');
        Route::delete('scenarios/{id}', [ScenarioController::class, 'destroy'])->whereUuid('id')->name('liquidity-planning.scenarios.destroy');
        Route::post('alerts/evaluate', [AlertController::class, 'evaluate'])->name('liquidity-planning.alerts.evaluate');
        Route::post('alerts/{id}/acknowledge', [AlertController::class, 'acknowledge'])->whereUuid('id')->name('liquidity-planning.alerts.acknowledge');
        Route::post('alerts/{id}/resolve', [AlertController::class, 'resolve'])->whereUuid('id')->name('liquidity-planning.alerts.resolve');
    });
});
