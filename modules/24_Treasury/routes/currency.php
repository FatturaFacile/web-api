<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Treasury\Currency\Http\Controllers\CurrencyExposureController;
use Modules\Treasury\Currency\Http\Controllers\CurrencyRateController;
use Modules\Treasury\Currency\Http\Controllers\CurrencySettingsController;
use Modules\Treasury\Currency\Http\Controllers\CurrencySyncController;
use Modules\Treasury\Currency\Http\Controllers\FxRevaluationController;

/*
|--------------------------------------------------------------------------
| Multi-currency engine and exchange rates (Mod 003 / Issue #249)
|--------------------------------------------------------------------------
| Rates (official ECB / SNB / ESTV and the company's manual and contract rates), the rate that applies on a day, the manual
| synchronisation and its history, the currency exposure, the currency settings, and the period-end FX revaluation of the ledger.
| Amounts and rates are decimal strings, days Y-m-d.
*/
Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:currencies'])
    ->group(function (): void {
        Route::get('currencies/rates/resolve', [CurrencyRateController::class, 'resolve'])->name('currencies.rates.resolve');
        Route::get('currencies/rates/export', [CurrencyRateController::class, 'export'])->name('currencies.rates.export');
        Route::post('currencies/rates/sync', [CurrencySyncController::class, 'sync'])->name('currencies.rates.sync');
        Route::get('currencies/rates', [CurrencyRateController::class, 'index'])->name('currencies.rates.index');
        Route::post('currencies/rates', [CurrencyRateController::class, 'store'])->name('currencies.rates.store');
        Route::get('currencies/sync-runs', [CurrencySyncController::class, 'runs'])->name('currencies.sync-runs.index');
        Route::get('currencies/exposure', [CurrencyExposureController::class, 'show'])->name('currencies.exposure');
        Route::get('settings/currencies', [CurrencySettingsController::class, 'show'])->name('settings.currencies.show');
        Route::put('settings/currencies', [CurrencySettingsController::class, 'update'])->name('settings.currencies.update');
    });

Route::prefix('api/v1/accounting')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:ledger'])
    ->group(function (): void {
        Route::get('fx-revaluations', [FxRevaluationController::class, 'index'])->name('accounting.fx-revaluations.index');
        Route::post('fx-revaluations', [FxRevaluationController::class, 'store'])->name('accounting.fx-revaluations.store');
        Route::get('fx-revaluations/{id}', [FxRevaluationController::class, 'show'])->whereUuid('id')->name('accounting.fx-revaluations.show');
        Route::post('fx-revaluations/{id}/post', [FxRevaluationController::class, 'post'])->whereUuid('id')->name('accounting.fx-revaluations.post');
        Route::post('fx-revaluations/{id}/reverse', [FxRevaluationController::class, 'reverse'])->whereUuid('id')->name('accounting.fx-revaluations.reverse');
    });
