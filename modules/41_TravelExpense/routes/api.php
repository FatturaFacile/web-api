<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\TravelExpense\Http\Controllers\LegController;
use Modules\TravelExpense\Http\Controllers\MileageClaimController;
use Modules\TravelExpense\Http\Controllers\PerDiemController;
use Modules\TravelExpense\Http\Controllers\RateController;
use Modules\TravelExpense\Http\Controllers\TripController;
use Modules\TravelExpense\Http\Controllers\TripExpenseController;
use Modules\TravelExpense\Http\Controllers\TripReportController;
use Modules\TravelExpense\Http\Controllers\TripWorkflowController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Business trips and travel expenses. module.access:travel lets every member read (GET) and write (the policies keep a
| member on their own trips); owners, administrators, HR and accountants see every trip, approve and maintain the
| rate tables, and finance (owners, administrators, accountants) records the advance and reconciles.
*/
Route::prefix('api/v1/travel')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:travel'])
    ->name('api.v1.travel.')
    ->where(['id' => $uuid, 'itemId' => $uuid])
    ->group(function (): void {
        Route::get('/trips', [TripController::class, 'index'])->name('trips.index');
        Route::post('/trips', [TripController::class, 'store'])->name('trips.store');
        Route::get('/trips/{id}', [TripController::class, 'show'])->name('trips.show');
        Route::put('/trips/{id}', [TripController::class, 'update'])->name('trips.update');
        Route::delete('/trips/{id}', [TripController::class, 'destroy'])->name('trips.destroy');

        Route::post('/trips/{id}/submit', [TripWorkflowController::class, 'submit'])->name('trips.submit');
        Route::post('/trips/{id}/withdraw', [TripWorkflowController::class, 'withdraw'])->name('trips.withdraw');
        Route::post('/trips/{id}/approve', [TripWorkflowController::class, 'approve'])->name('trips.approve');
        Route::post('/trips/{id}/reject', [TripWorkflowController::class, 'reject'])->name('trips.reject');
        Route::post('/trips/{id}/reopen', [TripWorkflowController::class, 'reopen'])->name('trips.reopen');
        Route::post('/trips/{id}/complete', [TripWorkflowController::class, 'complete'])->name('trips.complete');
        Route::post('/trips/{id}/advance-paid', [TripWorkflowController::class, 'advancePaid'])->name('trips.advance-paid');
        Route::post('/trips/{id}/reconcile', [TripWorkflowController::class, 'reconcile'])->name('trips.reconcile');

        Route::get('/trips/{id}/reconciliation', [TripReportController::class, 'reconciliation'])->name('trips.reconciliation');
        Route::get('/trips/{id}/report', [TripReportController::class, 'report'])->name('trips.report');

        Route::post('/trips/{id}/legs', [LegController::class, 'store'])->name('legs.store');
        Route::put('/trips/{id}/legs/{itemId}', [LegController::class, 'update'])->name('legs.update');
        Route::delete('/trips/{id}/legs/{itemId}', [LegController::class, 'destroy'])->name('legs.destroy');

        Route::get('/trips/{id}/per-diem', [PerDiemController::class, 'index'])->name('per-diem.index');
        Route::put('/trips/{id}/per-diem', [PerDiemController::class, 'calculate'])->name('per-diem.calculate');

        Route::post('/trips/{id}/mileage-claims', [MileageClaimController::class, 'store'])->name('mileage-claims.store');
        Route::put('/trips/{id}/mileage-claims/{itemId}', [MileageClaimController::class, 'update'])->name('mileage-claims.update');
        Route::delete('/trips/{id}/mileage-claims/{itemId}', [MileageClaimController::class, 'destroy'])->name('mileage-claims.destroy');

        Route::get('/trips/{id}/expenses', [TripExpenseController::class, 'index'])->name('expenses.index');
        Route::post('/trips/{id}/expenses', [TripExpenseController::class, 'store'])->name('expenses.store');
        Route::delete('/trips/{id}/expenses/{itemId}', [TripExpenseController::class, 'destroy'])->name('expenses.destroy');

        Route::get('/rates/per-diem', [RateController::class, 'perDiemIndex'])->name('rates.per-diem.index');
        Route::post('/rates/per-diem', [RateController::class, 'perDiemStore'])->name('rates.per-diem.store');
        Route::put('/rates/per-diem/{id}', [RateController::class, 'perDiemUpdate'])->name('rates.per-diem.update');
        Route::delete('/rates/per-diem/{id}', [RateController::class, 'perDiemDestroy'])->name('rates.per-diem.destroy');
        Route::get('/rates/mileage', [RateController::class, 'mileageIndex'])->name('rates.mileage.index');
        Route::post('/rates/mileage', [RateController::class, 'mileageStore'])->name('rates.mileage.store');
        Route::put('/rates/mileage/{id}', [RateController::class, 'mileageUpdate'])->name('rates.mileage.update');
        Route::delete('/rates/mileage/{id}', [RateController::class, 'mileageDestroy'])->name('rates.mileage.destroy');
        Route::post('/rates/defaults', [RateController::class, 'seedDefaults'])->name('rates.defaults');
    });
