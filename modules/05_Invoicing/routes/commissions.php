<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Invoicing\Http\Controllers\SalesCommissionController;

Route::prefix('api/v1/invoicing')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:invoicing'])
    ->where(['id' => '[0-9a-fA-F-]{36}'])
    ->name('invoicing.')
    ->group(function (): void {
        // Sales targets
        Route::post('sales-teams', [SalesCommissionController::class, 'storeTeam'])->name('sales-teams.store');
        Route::post('sales-targets', [SalesCommissionController::class, 'storeTarget'])->name('sales-targets.store');
        Route::get('sales-targets/attainment', [SalesCommissionController::class, 'attainment'])->name('sales-targets.attainment');

        // Commissions
        Route::post('commissions/accrue', [SalesCommissionController::class, 'accrue'])->name('commissions.accrue');
        Route::get('commission-statements', [SalesCommissionController::class, 'statements'])->name('commission-statements.index');
        Route::post('commission-statements', [SalesCommissionController::class, 'storeStatement'])->name('commission-statements.store');
        Route::post('commission-statements/{id}/approve', [SalesCommissionController::class, 'approve'])->name('commission-statements.approve');
        Route::post('commission-statements/{id}/post', [SalesCommissionController::class, 'post'])->name('commission-statements.post');
        Route::post('commission-statements/{id}/paid', [SalesCommissionController::class, 'markPaid'])->name('commission-statements.paid');
        Route::put('commission-settings', [SalesCommissionController::class, 'saveSettings'])->name('commission-settings.save');
    });
