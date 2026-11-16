<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Plm\Http\Controllers\ChangeOrderController;
use Modules\Plm\Http\Controllers\RevisionController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Product lifecycle management. module.access:plm lets every member read (GET) and only the roles with write access of
| config/permissions.php change anything; the policy keeps the records inside the company. Deciding a change order is
| reserved to the approvers named on it.
*/
Route::prefix('api/v1/plm')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:plm'])
    ->name('api.v1.plm.')
    ->where(['id' => $uuid])
    ->group(function (): void {
        Route::get('/change-orders', [ChangeOrderController::class, 'index'])->name('change-orders.index');
        Route::post('/change-orders', [ChangeOrderController::class, 'store'])->name('change-orders.store');
        Route::get('/change-orders/{id}', [ChangeOrderController::class, 'show'])->name('change-orders.show');
        Route::put('/change-orders/{id}', [ChangeOrderController::class, 'update'])->name('change-orders.update');
        Route::delete('/change-orders/{id}', [ChangeOrderController::class, 'destroy'])->name('change-orders.destroy');
        Route::post('/change-orders/{id}/submit', [ChangeOrderController::class, 'submit'])->name('change-orders.submit');
        Route::post('/change-orders/{id}/decide', [ChangeOrderController::class, 'decide'])->name('change-orders.decide');
        Route::post('/change-orders/{id}/cancel', [ChangeOrderController::class, 'cancel'])->name('change-orders.cancel');
        Route::post('/change-orders/{id}/implement', [ChangeOrderController::class, 'implement'])->name('change-orders.implement');

        Route::get('/revisions', [RevisionController::class, 'index'])->name('revisions.index');
        Route::post('/revisions', [RevisionController::class, 'store'])->name('revisions.store');
        Route::get('/revisions/{id}', [RevisionController::class, 'show'])->name('revisions.show');
        Route::put('/revisions/{id}', [RevisionController::class, 'update'])->name('revisions.update');
        Route::delete('/revisions/{id}', [RevisionController::class, 'destroy'])->name('revisions.destroy');
        Route::post('/revisions/{id}/release', [RevisionController::class, 'release'])->name('revisions.release');
        Route::post('/revisions/{id}/obsolete', [RevisionController::class, 'obsolete'])->name('revisions.obsolete');
    });
