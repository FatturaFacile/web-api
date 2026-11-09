<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\HealthExpenses\Http\Controllers\HealthExpenseController;

Route::prefix('api/v1/tessera-sanitaria')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:health_expenses'])
    ->group(function (): void {
        Route::get('settings', [HealthExpenseController::class, 'settings'])->name('tessera-sanitaria.settings.show');
        Route::put('settings', [HealthExpenseController::class, 'updateSettings'])->name('tessera-sanitaria.settings.update');
        Route::get('expenses', [HealthExpenseController::class, 'index'])->name('tessera-sanitaria.expenses.index');
        Route::post('expenses', [HealthExpenseController::class, 'store'])->name('tessera-sanitaria.expenses.store');
        Route::get('expenses/{id}', [HealthExpenseController::class, 'show'])->whereUuid('id')->name('tessera-sanitaria.expenses.show');
        Route::get('summary', [HealthExpenseController::class, 'summary'])->name('tessera-sanitaria.summary');
        Route::delete('expenses/{id}', [HealthExpenseController::class, 'destroy'])->name('tessera-sanitaria.expenses.destroy');
        Route::get('export', [HealthExpenseController::class, 'export'])->name('tessera-sanitaria.export');
    });
