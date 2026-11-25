<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\VanSalesAndFieldForce\Http\Controllers\FieldRouteAndCatalogController;
use Modules\VanSalesAndFieldForce\Http\Controllers\InFieldVisitAndSyncController;
use Modules\VanSalesAndFieldForce\Http\Controllers\VanEndOfDaySettlementController;
use Modules\VanSalesAndFieldForce\Http\Controllers\VanLoadManifestController;
use Modules\VanSalesAndFieldForce\Http\Controllers\VanSellingConfigController;
use Modules\VanSalesAndFieldForce\Http\Controllers\VanWarehouseController;

/*
|--------------------------------------------------------------------------
| Mod 233: Van Selling (Tentata Vendita su Furgone) & Offline Field Sales
|--------------------------------------------------------------------------
*/
Route::prefix('api/v1/van-selling')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:van_sales_and_field_force'])
    ->name('api.v1.van-selling.')
    ->group(function (): void {
        Route::get('/config', [VanSellingConfigController::class, 'show'])->name('config');

        Route::get('/vans', [VanWarehouseController::class, 'index'])->name('vans.index');
        Route::post('/vans', [VanWarehouseController::class, 'store'])->name('vans.store');
        Route::get('/vans/{id}', [VanWarehouseController::class, 'show'])->whereUuid('id')->name('vans.show');
        Route::put('/vans/{id}', [VanWarehouseController::class, 'update'])->whereUuid('id')->name('vans.update');
        Route::post('/vans/{id}/temperature', [VanWarehouseController::class, 'recordTemperature'])->whereUuid('id')->name('vans.temperature');
        Route::post('/vans/{id}/reserve-sectional-batch', [VanWarehouseController::class, 'reserveSectionalBatch'])->whereUuid('id')->name('vans.reserve-sectional-batch');

        Route::get('/load-manifests', [VanLoadManifestController::class, 'index'])->name('load-manifests.index');
        Route::get('/load-manifests/replenishment-suggestions', [VanLoadManifestController::class, 'replenishmentSuggestions'])->name('load-manifests.replenishment-suggestions');
        Route::post('/load-manifests', [VanLoadManifestController::class, 'store'])->name('load-manifests.store');
        Route::get('/load-manifests/{id}', [VanLoadManifestController::class, 'show'])->whereUuid('id')->name('load-manifests.show');
        Route::post('/load-manifests/{id}/issue-global-ddt', [VanLoadManifestController::class, 'issueGlobalDdt'])->whereUuid('id')->name('load-manifests.issue-global-ddt');

        Route::get('/route-plans', [FieldRouteAndCatalogController::class, 'index'])->name('route-plans.index');
        Route::post('/route-plans', [FieldRouteAndCatalogController::class, 'store'])->name('route-plans.store');
        Route::get('/route-plans/{id}', [FieldRouteAndCatalogController::class, 'show'])->whereUuid('id')->name('route-plans.show');
        Route::get('/customers/{contactId}/field-dossier', [FieldRouteAndCatalogController::class, 'customerDossier'])->whereUuid('contactId')->name('customers.field-dossier');
        Route::post('/customers/{contactId}/unblock-credit', [FieldRouteAndCatalogController::class, 'unblockCredit'])->whereUuid('contactId')->name('customers.unblock-credit');
        Route::post('/gs1-parse', [FieldRouteAndCatalogController::class, 'parseGs1'])->name('gs1-parse');

        Route::get('/visits', [InFieldVisitAndSyncController::class, 'index'])->name('visits.index');
        Route::post('/visits', [InFieldVisitAndSyncController::class, 'store'])->name('visits.store');
        Route::get('/visits/{id}', [InFieldVisitAndSyncController::class, 'show'])->whereUuid('id')->name('visits.show');
        Route::get('/visits/{id}/thermal-print', [InFieldVisitAndSyncController::class, 'thermalPrint'])->whereUuid('id')->name('visits.thermal-print');
        Route::post('/offline-sync', [InFieldVisitAndSyncController::class, 'offlineSync'])->name('offline-sync');

        Route::get('/settlements', [VanEndOfDaySettlementController::class, 'index'])->name('settlements.index');
        Route::post('/settlements/preview', [VanEndOfDaySettlementController::class, 'preview'])->name('settlements.preview');
        Route::post('/settlements', [VanEndOfDaySettlementController::class, 'store'])->name('settlements.store');
        Route::get('/settlements/{id}', [VanEndOfDaySettlementController::class, 'show'])->whereUuid('id')->name('settlements.show');
    });
