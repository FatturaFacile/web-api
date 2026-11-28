<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\BondedWarehouses\Http\Controllers\AeoController;
use Modules\BondedWarehouses\Http\Controllers\AuthorizationController;
use Modules\BondedWarehouses\Http\Controllers\CguController;
use Modules\BondedWarehouses\Http\Controllers\ExportController;
use Modules\BondedWarehouses\Http\Controllers\MrnLotController;
use Modules\BondedWarehouses\Http\Controllers\OverviewController;
use Modules\BondedWarehouses\Http\Controllers\VatDepotController;

/*
|--------------------------------------------------------------------------
| Customs warehouses (CDU 7100 / OZL), IVA warehouses (Art. 50-bis DL 331/93) and AEO (Mod 353 / Issue #599)
|--------------------------------------------------------------------------
*/
Route::prefix('api/v1/bonded-warehouses')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:bonded_warehouses'])
    ->group(function (): void {
        Route::get('dashboard', [OverviewController::class, 'dashboard'])->name('bonded-warehouses.dashboard');
        Route::get('reference', [OverviewController::class, 'reference'])->name('bonded-warehouses.reference');

        Route::get('authorizations', [AuthorizationController::class, 'index'])->name('bonded-warehouses.authorizations.index');
        Route::post('authorizations', [AuthorizationController::class, 'store'])->name('bonded-warehouses.authorizations.store');
        Route::get('authorizations/{id}', [AuthorizationController::class, 'show'])->whereUuid('id')->name('bonded-warehouses.authorizations.show');
        Route::put('authorizations/{id}', [AuthorizationController::class, 'update'])->whereUuid('id')->name('bonded-warehouses.authorizations.update');
        Route::delete('authorizations/{id}', [AuthorizationController::class, 'destroy'])->whereUuid('id')->name('bonded-warehouses.authorizations.destroy');

        Route::get('mrn-lots', [MrnLotController::class, 'index'])->name('bonded-warehouses.mrn-lots.index');
        Route::post('mrn-lots', [MrnLotController::class, 'store'])->name('bonded-warehouses.mrn-lots.store');
        Route::post('mrn-lots/discharge/simulate', [MrnLotController::class, 'simulate'])->name('bonded-warehouses.mrn-lots.discharge.simulate');
        Route::post('mrn-lots/discharge', [MrnLotController::class, 'discharge'])->name('bonded-warehouses.mrn-lots.discharge');
        Route::get('mrn-lots/{id}', [MrnLotController::class, 'show'])->whereUuid('id')->name('bonded-warehouses.mrn-lots.show');

        Route::get('cgu/plafond', [CguController::class, 'plafond'])->name('bonded-warehouses.cgu.plafond');
        Route::get('cgu/ledger', [CguController::class, 'ledger'])->name('bonded-warehouses.cgu.ledger');

        Route::get('vat-register', [VatDepotController::class, 'index'])->name('bonded-warehouses.vat-register.index');
        Route::post('vat-register/inbound', [VatDepotController::class, 'inbound'])->name('bonded-warehouses.vat-register.inbound');
        Route::post('vat-register/extraction/preview', [VatDepotController::class, 'preview'])->name('bonded-warehouses.vat-register.extraction.preview');
        Route::post('vat-register/extraction', [VatDepotController::class, 'extract'])->name('bonded-warehouses.vat-register.extraction');

        Route::get('aeo/dashboard', [AeoController::class, 'dashboard'])->name('bonded-warehouses.aeo.dashboard');
        Route::get('aeo/audits', [AeoController::class, 'audits'])->name('bonded-warehouses.aeo.audits');
        Route::post('aeo/reconciliation', [AeoController::class, 'reconcile'])->name('bonded-warehouses.aeo.reconciliation');

        Route::get('exports/{dataset}.{format}', [ExportController::class, 'export'])
            ->whereIn('dataset', ['mrn-lots', 'cgu-ledger', 'vat-register', 'aeo-audits'])
            ->whereIn('format', ['csv', 'pdf'])
            ->name('bonded-warehouses.exports');
    });
