<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\FranchiseNetwork\Http\Controllers\AgreementController;
use Modules\FranchiseNetwork\Http\Controllers\AuditController;
use Modules\FranchiseNetwork\Http\Controllers\CatalogPushController;
use Modules\FranchiseNetwork\Http\Controllers\FormulaController;
use Modules\FranchiseNetwork\Http\Controllers\LookupController;
use Modules\FranchiseNetwork\Http\Controllers\NetworkController;
use Modules\FranchiseNetwork\Http\Controllers\OperationsController;
use Modules\FranchiseNetwork\Http\Controllers\PortalController;
use Modules\FranchiseNetwork\Http\Controllers\RoyaltyRunController;
use Modules\FranchiseNetwork\Http\Controllers\StoreController;

/*
|--------------------------------------------------------------------------
| Franchise network governance (Mod 244 / Issue #490)
|--------------------------------------------------------------------------
| Franchisor side: stores, affiliation agreements (L. 129/2004), royalty and marketing fund formulas, central catalogue / price /
| promotion / loyalty pushes, sell-out, monthly billing runs with SEPA SDD / LSV+ batches, store audits and the network benchmark.
| Franchisee side (portal/*): contract and obligations, pushes received, statements and the anonymous benchmark of its own store.
*/
Route::prefix('api/v1/franchise-network')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:franchise_network'])
    ->group(function (): void {
        Route::get('lookups', [LookupController::class, 'lookups'])->name('franchise-network.lookups');
        Route::get('dashboard', [NetworkController::class, 'dashboard'])->name('franchise-network.dashboard');

        Route::get('stores', [StoreController::class, 'index'])->name('franchise-network.stores.index');
        Route::post('stores', [StoreController::class, 'store'])->name('franchise-network.stores.store');
        Route::get('stores/{id}', [StoreController::class, 'show'])->whereUuid('id')->name('franchise-network.stores.show');
        Route::put('stores/{id}', [StoreController::class, 'update'])->whereUuid('id')->name('franchise-network.stores.update');
        Route::delete('stores/{id}', [StoreController::class, 'destroy'])->whereUuid('id')->name('franchise-network.stores.destroy');
        Route::post('stores/{id}/link-code', [StoreController::class, 'linkCode'])->whereUuid('id')->name('franchise-network.stores.link-code');
        Route::put('stores/{id}/sell-out/{period}', [OperationsController::class, 'sellOutSave'])->whereUuid('id')->name('franchise-network.sell-out.save');
        Route::put('stores/{id}/purchases/{period}', [OperationsController::class, 'purchasesSave'])->whereUuid('id')->name('franchise-network.purchases.save');

        Route::get('formulas', [FormulaController::class, 'index'])->name('franchise-network.formulas.index');
        Route::post('formulas', [FormulaController::class, 'store'])->name('franchise-network.formulas.store');
        Route::put('formulas/{id}', [FormulaController::class, 'update'])->whereUuid('id')->name('franchise-network.formulas.update');
        Route::delete('formulas/{id}', [FormulaController::class, 'destroy'])->whereUuid('id')->name('franchise-network.formulas.destroy');
        Route::post('formulas/{id}/clone', [FormulaController::class, 'duplicate'])->whereUuid('id')->name('franchise-network.formulas.clone');
        Route::post('formulas/{id}/simulate', [FormulaController::class, 'simulate'])->whereUuid('id')->name('franchise-network.formulas.simulate');

        Route::get('agreements', [AgreementController::class, 'index'])->name('franchise-network.agreements.index');
        Route::get('agreements/alerts', [AgreementController::class, 'alerts'])->name('franchise-network.agreements.alerts');
        Route::post('agreements', [AgreementController::class, 'store'])->name('franchise-network.agreements.store');
        Route::get('agreements/{id}', [AgreementController::class, 'show'])->whereUuid('id')->name('franchise-network.agreements.show');
        Route::put('agreements/{id}', [AgreementController::class, 'update'])->whereUuid('id')->name('franchise-network.agreements.update');
        Route::delete('agreements/{id}', [AgreementController::class, 'destroy'])->whereUuid('id')->name('franchise-network.agreements.destroy');
        Route::post('agreements/{id}/activate', [AgreementController::class, 'activate'])->whereUuid('id')->name('franchise-network.agreements.activate');
        Route::post('agreements/{id}/terminate', [AgreementController::class, 'terminate'])->whereUuid('id')->name('franchise-network.agreements.terminate');
        Route::post('agreements/{id}/renew', [AgreementController::class, 'renew'])->whereUuid('id')->name('franchise-network.agreements.renew');

        Route::get('pushes', [CatalogPushController::class, 'index'])->name('franchise-network.pushes.index');
        Route::post('pushes', [CatalogPushController::class, 'store'])->name('franchise-network.pushes.store');
        Route::get('pushes/{id}', [CatalogPushController::class, 'show'])->whereUuid('id')->name('franchise-network.pushes.show');
        Route::post('pushes/{id}/dispatch', [CatalogPushController::class, 'deliver'])->whereUuid('id')->name('franchise-network.pushes.dispatch');
        Route::post('pushes/{id}/retry', [CatalogPushController::class, 'retry'])->whereUuid('id')->name('franchise-network.pushes.retry');

        Route::get('sell-out', [OperationsController::class, 'sellOutIndex'])->name('franchise-network.sell-out.index');
        Route::post('sell-out/import', [OperationsController::class, 'sellOutImport'])->name('franchise-network.sell-out.import');
        Route::get('purchases', [OperationsController::class, 'purchasesIndex'])->name('franchise-network.purchases.index');
        Route::get('loyalty/transactions', [OperationsController::class, 'loyaltyIndex'])->name('franchise-network.loyalty.index');
        Route::post('loyalty/transactions', [OperationsController::class, 'loyaltyStore'])->name('franchise-network.loyalty.store');
        Route::get('loyalty/settlement', [OperationsController::class, 'loyaltySettlement'])->name('franchise-network.loyalty.settlement');

        Route::get('royalty-runs', [RoyaltyRunController::class, 'index'])->name('franchise-network.runs.index');
        Route::post('royalty-runs', [RoyaltyRunController::class, 'calculate'])->name('franchise-network.runs.calculate');
        Route::get('royalty-runs/{id}', [RoyaltyRunController::class, 'show'])->whereUuid('id')->name('franchise-network.runs.show');
        Route::post('royalty-runs/{id}/invoice', [RoyaltyRunController::class, 'invoice'])->whereUuid('id')->name('franchise-network.runs.invoice');
        Route::get('royalty-runs/{id}/batches', [RoyaltyRunController::class, 'batches'])->whereUuid('id')->name('franchise-network.runs.batches');

        Route::get('audits', [AuditController::class, 'index'])->name('franchise-network.audits.index');
        Route::post('audits', [AuditController::class, 'store'])->name('franchise-network.audits.store');
        Route::get('audits/{id}', [AuditController::class, 'show'])->whereUuid('id')->name('franchise-network.audits.show');
        Route::put('audits/{id}/answers', [AuditController::class, 'answers'])->whereUuid('id')->name('franchise-network.audits.answers');
        Route::post('audits/{id}/photos', [AuditController::class, 'addPhoto'])->whereUuid('id')->name('franchise-network.audits.photos.store');
        Route::get('audits/{id}/photos/{photoId}', [AuditController::class, 'photo'])->whereUuid(['id', 'photoId'])->name('franchise-network.audits.photos.show');
        Route::delete('audits/{id}/photos/{photoId}', [AuditController::class, 'removePhoto'])->whereUuid(['id', 'photoId'])->name('franchise-network.audits.photos.destroy');
        Route::post('audits/{id}/submit', [AuditController::class, 'submit'])->whereUuid('id')->name('franchise-network.audits.submit');
        Route::get('audit-actions', [AuditController::class, 'actions'])->name('franchise-network.audit-actions.index');
        Route::post('audit-actions/{id}/complete', [AuditController::class, 'completeAction'])->whereUuid('id')->name('franchise-network.audit-actions.complete');

        Route::post('benchmark/snapshot', [NetworkController::class, 'snapshot'])->name('franchise-network.benchmark.snapshot');
        Route::get('benchmark/stats', [NetworkController::class, 'stats'])->name('franchise-network.benchmark.stats');

        Route::get('portal/me', [PortalController::class, 'me'])->name('franchise-network.portal.me');
        Route::post('portal/link', [PortalController::class, 'link'])->name('franchise-network.portal.link');
        Route::get('portal/contract', [PortalController::class, 'contract'])->name('franchise-network.portal.contract');
        Route::get('portal/syncs', [PortalController::class, 'syncs'])->name('franchise-network.portal.syncs');
        Route::post('portal/syncs/{id}/acknowledge', [PortalController::class, 'acknowledge'])->whereUuid('id')->name('franchise-network.portal.acknowledge');
        Route::get('portal/statements', [PortalController::class, 'statements'])->name('franchise-network.portal.statements');
        Route::get('portal/benchmark', [PortalController::class, 'benchmark'])->name('franchise-network.portal.benchmark');
    });
