<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Rebates\Http\Controllers\AgreementController;
use Modules\Rebates\Http\Controllers\PromotionController;
use Modules\Rebates\Http\Controllers\SettlementController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Volume rebates and trade promotions. module.access:rebates lets every finance and sales member read (GET) and only the
| roles with write access of config/permissions.php change anything; the policy keeps the records inside the company.
*/
Route::prefix('api/v1/rebates')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:rebates'])
    ->name('api.v1.rebates.')
    ->where(['id' => $uuid])
    ->group(function (): void {
        Route::get('/dashboard', [SettlementController::class, 'summary'])->name('dashboard');

        Route::get('/agreements', [AgreementController::class, 'index'])->name('agreements.index');
        Route::post('/agreements', [AgreementController::class, 'store'])->name('agreements.store');
        Route::get('/agreements/{id}', [AgreementController::class, 'show'])->name('agreements.show');
        Route::put('/agreements/{id}', [AgreementController::class, 'update'])->name('agreements.update');
        Route::delete('/agreements/{id}', [AgreementController::class, 'destroy'])->name('agreements.destroy');
        Route::post('/agreements/{id}/activate', [AgreementController::class, 'activate'])->name('agreements.activate');
        Route::post('/agreements/{id}/close', [AgreementController::class, 'close'])->name('agreements.close');
        Route::post('/agreements/{id}/calculate', [AgreementController::class, 'calculate'])->name('agreements.calculate');
        Route::get('/agreements/{id}/accruals', [AgreementController::class, 'accruals'])->name('agreements.accruals');
        Route::post('/agreements/{id}/settle', [AgreementController::class, 'settle'])->name('agreements.settle');

        Route::get('/settlements', [SettlementController::class, 'index'])->name('settlements.index');

        Route::get('/promotions', [PromotionController::class, 'index'])->name('promotions.index');
        Route::post('/promotions', [PromotionController::class, 'store'])->name('promotions.store');
        Route::get('/promotions/{id}', [PromotionController::class, 'show'])->name('promotions.show');
        Route::put('/promotions/{id}', [PromotionController::class, 'update'])->name('promotions.update');
        Route::delete('/promotions/{id}', [PromotionController::class, 'destroy'])->name('promotions.destroy');
    });
