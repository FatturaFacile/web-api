<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Rebates\Http\Controllers\AgreementController;
use Modules\Rebates\Http\Controllers\PromotionController;
use Modules\Rebates\Http\Controllers\SettlementController;
use Modules\Rebates\Http\Controllers\SettlementProposalController;
use Modules\Rebates\Http\Controllers\SupplierProgramController;

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

        // Supplier rebate programmes (Mod 049): live progress, contributions, accrual runs and settlement proposals.
        Route::get('/agreements/{id}/progress', [SupplierProgramController::class, 'progress'])->name('agreements.progress');
        Route::get('/agreements/{id}/contributions', [SupplierProgramController::class, 'contributions'])->name('agreements.contributions.index');
        Route::post('/agreements/{id}/contributions', [SupplierProgramController::class, 'storeContribution'])->name('agreements.contributions.store');
        Route::put('/contributions/{id}', [SupplierProgramController::class, 'updateContribution'])->name('contributions.update');
        Route::delete('/contributions/{id}', [SupplierProgramController::class, 'destroyContribution'])->name('contributions.destroy');
        Route::get('/agreements/{id}/accrual-runs', [SupplierProgramController::class, 'runs'])->name('agreements.accrual-runs.index');
        Route::post('/agreements/{id}/accrual-runs', [SupplierProgramController::class, 'createRun'])->name('agreements.accrual-runs.store');
        Route::post('/accrual-runs/{id}/reverse', [SupplierProgramController::class, 'reverseRun'])->name('accrual-runs.reverse');

        Route::get('/settlement-proposals', [SettlementProposalController::class, 'index'])->name('settlement-proposals.index');
        Route::get('/settlement-proposals/{id}', [SettlementProposalController::class, 'show'])->name('settlement-proposals.show');
        Route::post('/agreements/{id}/settlement-proposals', [SettlementProposalController::class, 'store'])->name('agreements.settlement-proposals.store');
        Route::post('/settlement-proposals/{id}/submit', [SettlementProposalController::class, 'submit'])->name('settlement-proposals.submit');
        Route::post('/settlement-proposals/{id}/approve', [SettlementProposalController::class, 'approve'])->name('settlement-proposals.approve');
        Route::post('/settlement-proposals/{id}/execute', [SettlementProposalController::class, 'execute'])->name('settlement-proposals.execute');
        Route::post('/settlement-proposals/{id}/cancel', [SettlementProposalController::class, 'cancel'])->name('settlement-proposals.cancel');

        Route::get('/promotions', [PromotionController::class, 'index'])->name('promotions.index');
        Route::post('/promotions', [PromotionController::class, 'store'])->name('promotions.store');
        Route::get('/promotions/{id}', [PromotionController::class, 'show'])->name('promotions.show');
        Route::put('/promotions/{id}', [PromotionController::class, 'update'])->name('promotions.update');
        Route::delete('/promotions/{id}', [PromotionController::class, 'destroy'])->name('promotions.destroy');
    });
