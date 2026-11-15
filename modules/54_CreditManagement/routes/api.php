<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CreditManagement\Http\Controllers\CollectionCaseController;
use Modules\CreditManagement\Http\Controllers\DashboardController;
use Modules\CreditManagement\Http\Controllers\DisputeController;
use Modules\CreditManagement\Http\Controllers\DunningStrategyController;
use Modules\CreditManagement\Http\Controllers\ExposureController;
use Modules\CreditManagement\Http\Controllers\InsuranceController;
use Modules\CreditManagement\Http\Controllers\LimitRequestController;
use Modules\CreditManagement\Http\Controllers\PromiseToPayController;
use Modules\CreditManagement\Http\Controllers\ScorecardController;
use Modules\CreditManagement\Http\Controllers\ScoreController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Credit risk and collections. module.access:credit-management limits reading to finance and sales roles and writing to
| owners, administrators, accountants and sales managers; deciding a credit limit request or writing a case off is
| reserved by the policy to owners, administrators and members granted credit-management.approve.
*/
Route::prefix('api/v1/credit-management')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:credit-management'])
    ->name('api.v1.credit-management.')
    ->where(['id' => $uuid, 'customerId' => $uuid])
    ->group(function (): void {
        Route::get('/dashboard', [DashboardController::class, 'summary'])->name('dashboard');
        Route::get('/worklist', [DashboardController::class, 'worklist'])->name('worklist');

        Route::get('/scorecard/factors', [ScorecardController::class, 'factors'])->name('factors.index');
        Route::post('/scorecard/factors', [ScorecardController::class, 'storeFactor'])->name('factors.store');
        Route::put('/scorecard/factors/{id}', [ScorecardController::class, 'updateFactor'])->name('factors.update');
        Route::delete('/scorecard/factors/{id}', [ScorecardController::class, 'destroyFactor'])->name('factors.destroy');
        Route::get('/scorecard/bands', [ScorecardController::class, 'bands'])->name('bands.index');
        Route::post('/scorecard/bands', [ScorecardController::class, 'storeBand'])->name('bands.store');
        Route::put('/scorecard/bands/{id}', [ScorecardController::class, 'updateBand'])->name('bands.update');
        Route::delete('/scorecard/bands/{id}', [ScorecardController::class, 'destroyBand'])->name('bands.destroy');
        Route::post('/scorecard/defaults', [ScorecardController::class, 'defaults'])->name('scorecard.defaults');

        Route::get('/scores', [ScoreController::class, 'index'])->name('scores.index');
        Route::post('/scores', [ScoreController::class, 'store'])->name('scores.store');
        Route::get('/scores/{id}', [ScoreController::class, 'show'])->name('scores.show');
        Route::get('/customers/{customerId}/limit-proposal', [ScoreController::class, 'proposal'])->name('customers.limit-proposal');
        Route::get('/customers/{customerId}/exposure', [ExposureController::class, 'show'])->name('customers.exposure');

        Route::get('/exposures', [ExposureController::class, 'index'])->name('exposures.index');
        Route::get('/aging', [ExposureController::class, 'aging'])->name('aging');

        Route::get('/limit-requests', [LimitRequestController::class, 'index'])->name('limit-requests.index');
        Route::post('/limit-requests', [LimitRequestController::class, 'store'])->name('limit-requests.store');
        Route::get('/limit-requests/{id}', [LimitRequestController::class, 'show'])->name('limit-requests.show');
        Route::post('/limit-requests/{id}/approve', [LimitRequestController::class, 'approve'])->name('limit-requests.approve');
        Route::post('/limit-requests/{id}/reject', [LimitRequestController::class, 'reject'])->name('limit-requests.reject');
        Route::post('/limit-requests/{id}/cancel', [LimitRequestController::class, 'cancel'])->name('limit-requests.cancel');

        Route::get('/insurance-policies', [InsuranceController::class, 'policies'])->name('insurance-policies.index');
        Route::post('/insurance-policies', [InsuranceController::class, 'storePolicy'])->name('insurance-policies.store');
        Route::get('/insurance-policies/{id}', [InsuranceController::class, 'showPolicy'])->name('insurance-policies.show');
        Route::put('/insurance-policies/{id}', [InsuranceController::class, 'updatePolicy'])->name('insurance-policies.update');
        Route::delete('/insurance-policies/{id}', [InsuranceController::class, 'destroyPolicy'])->name('insurance-policies.destroy');
        Route::post('/insurance-policies/{id}/covers', [InsuranceController::class, 'storeCover'])->name('insurance-policies.covers.store');
        Route::get('/insurance-covers', [InsuranceController::class, 'covers'])->name('insurance-covers.index');
        Route::put('/insurance-covers/{id}', [InsuranceController::class, 'updateCover'])->name('insurance-covers.update');
        Route::delete('/insurance-covers/{id}', [InsuranceController::class, 'destroyCover'])->name('insurance-covers.destroy');

        Route::get('/dunning-strategies', [DunningStrategyController::class, 'index'])->name('dunning-strategies.index');
        Route::post('/dunning-strategies', [DunningStrategyController::class, 'store'])->name('dunning-strategies.store');
        Route::get('/dunning-strategies/{id}', [DunningStrategyController::class, 'show'])->name('dunning-strategies.show');
        Route::put('/dunning-strategies/{id}', [DunningStrategyController::class, 'update'])->name('dunning-strategies.update');
        Route::delete('/dunning-strategies/{id}', [DunningStrategyController::class, 'destroy'])->name('dunning-strategies.destroy');

        Route::get('/cases', [CollectionCaseController::class, 'index'])->name('cases.index');
        Route::post('/cases', [CollectionCaseController::class, 'store'])->name('cases.store');
        Route::get('/cases/{id}', [CollectionCaseController::class, 'show'])->name('cases.show');
        Route::put('/cases/{id}', [CollectionCaseController::class, 'update'])->name('cases.update');
        Route::post('/cases/{id}/assign', [CollectionCaseController::class, 'assign'])->name('cases.assign');
        Route::post('/cases/{id}/advance', [CollectionCaseController::class, 'advance'])->name('cases.advance');
        Route::post('/cases/{id}/actions', [CollectionCaseController::class, 'storeAction'])->name('cases.actions.store');
        Route::post('/cases/{id}/refresh', [CollectionCaseController::class, 'refresh'])->name('cases.refresh');
        Route::post('/cases/{id}/close', [CollectionCaseController::class, 'close'])->name('cases.close');

        Route::get('/promises', [PromiseToPayController::class, 'index'])->name('promises.index');
        Route::post('/promises', [PromiseToPayController::class, 'store'])->name('promises.store');
        Route::post('/promises/mark-broken', [PromiseToPayController::class, 'markBroken'])->name('promises.mark-broken');
        Route::post('/promises/{id}/resolve', [PromiseToPayController::class, 'resolve'])->name('promises.resolve');

        Route::get('/disputes', [DisputeController::class, 'index'])->name('disputes.index');
        Route::post('/disputes', [DisputeController::class, 'store'])->name('disputes.store');
        Route::get('/disputes/{id}', [DisputeController::class, 'show'])->name('disputes.show');
        Route::post('/disputes/{id}/review', [DisputeController::class, 'review'])->name('disputes.review');
        Route::post('/disputes/{id}/resolve', [DisputeController::class, 'resolve'])->name('disputes.resolve');
    });
