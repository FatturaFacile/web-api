<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Subcontracting\Http\Controllers\ActiveFlowController;
use Modules\Subcontracting\Http\Controllers\PassiveFlowController;
use Modules\Subcontracting\Http\Controllers\SubcontractingOrderController;
use Modules\Subcontracting\Http\Controllers\SubcontractingReportController;

/*
|--------------------------------------------------------------------------
| Lavoro conto terzi: conto lavoro passivo (terzisti) e attivo (Mod 065 / Issue #270)
|--------------------------------------------------------------------------
| Orders, the dispatch / receipt / return flows with their DDTs, the stock-at-subcontractors dashboard, the statutory register
| (Art. 53 DPR 633/72) and the IT/CH TPP/TPA summary.
*/
Route::prefix('api/v1/subcontracting')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:subcontracting'])
    ->group(function (): void {
        Route::get('lookups', [SubcontractingReportController::class, 'lookups'])->name('subcontracting.lookups');
        Route::get('dashboard', [SubcontractingReportController::class, 'dashboard'])->name('subcontracting.dashboard');
        Route::get('register', [SubcontractingReportController::class, 'register'])->name('subcontracting.register');
        Route::get('register/export/{format}', [SubcontractingReportController::class, 'export'])->whereIn('format', ['csv', 'xlsx', 'pdf'])->name('subcontracting.register.export');
        Route::get('cross-border', [SubcontractingReportController::class, 'crossBorder'])->name('subcontracting.cross-border');

        Route::get('orders', [SubcontractingOrderController::class, 'index'])->name('subcontracting.orders.index');
        Route::post('orders', [SubcontractingOrderController::class, 'store'])->name('subcontracting.orders.store');
        Route::get('orders/{id}', [SubcontractingOrderController::class, 'show'])->whereUuid('id')->name('subcontracting.orders.show');
        Route::post('orders/{id}/confirm', [SubcontractingOrderController::class, 'confirm'])->whereUuid('id')->name('subcontracting.orders.confirm');
        Route::post('orders/{id}/cancel', [SubcontractingOrderController::class, 'cancel'])->whereUuid('id')->name('subcontracting.orders.cancel');

        Route::post('orders/{id}/dispatch', [PassiveFlowController::class, 'dispatch'])->whereUuid('id')->name('subcontracting.orders.dispatch');
        Route::post('orders/{id}/receive-preview', [PassiveFlowController::class, 'preview'])->whereUuid('id')->name('subcontracting.orders.receive-preview');
        Route::post('orders/{id}/receive', [PassiveFlowController::class, 'receive'])->whereUuid('id')->name('subcontracting.orders.receive');
        Route::post('orders/{id}/return-unused', [PassiveFlowController::class, 'returnUnused'])->whereUuid('id')->name('subcontracting.orders.return-unused');

        Route::post('orders/{id}/receive-customer-materials', [ActiveFlowController::class, 'receiveMaterials'])->whereUuid('id')->name('subcontracting.orders.receive-customer-materials');
        Route::post('orders/{id}/production', [ActiveFlowController::class, 'production'])->whereUuid('id')->name('subcontracting.orders.production');
        Route::post('orders/{id}/deliver', [ActiveFlowController::class, 'deliver'])->whereUuid('id')->name('subcontracting.orders.deliver');
    });
