<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Contracts\Http\Controllers\ContractDocumentController;
use Modules\Contracts\Http\Controllers\ContractLifecycleController;
use Modules\Contracts\Http\Controllers\ContractObligationController;
use Modules\Contracts\Http\Controllers\ContractPartyController;
use Modules\Contracts\Http\Controllers\ContractRegisterController;

Route::prefix('api/v1/contract-register')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:contracts'])
    ->group(function (): void {
        Route::get('/', [ContractRegisterController::class, 'index'])->name('contracts.index');
        Route::post('/', [ContractRegisterController::class, 'store'])->name('contracts.store');
        Route::get('alerts', [ContractRegisterController::class, 'alerts'])->name('contracts.alerts');
        Route::post('alerts/{id}/acknowledge', [ContractRegisterController::class, 'acknowledgeAlert'])->whereUuid('id')->name('contracts.alerts.acknowledge');
        Route::get('obligations', [ContractObligationController::class, 'index'])->name('contracts.obligations.index');
        Route::put('obligations/{obligationId}', [ContractObligationController::class, 'update'])->whereUuid('obligationId')->name('contracts.obligations.update');
        Route::delete('obligations/{obligationId}', [ContractObligationController::class, 'destroy'])->whereUuid('obligationId')->name('contracts.obligations.destroy');
        Route::post('obligations/{obligationId}/complete', [ContractObligationController::class, 'complete'])->whereUuid('obligationId')->name('contracts.obligations.complete');
        Route::post('obligations/{obligationId}/waive', [ContractObligationController::class, 'waive'])->whereUuid('obligationId')->name('contracts.obligations.waive');
        Route::get('{kind}/{id}', [ContractRegisterController::class, 'show'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.show');
        Route::put('{kind}/{id}/terms', [ContractRegisterController::class, 'updateTerms'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.terms');
        Route::post('{kind}/{id}/renew', [ContractRegisterController::class, 'renew'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.renew');
        Route::post('{kind}/{id}/notice', [ContractRegisterController::class, 'giveNotice'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.notice');

        Route::post('{kind}/{id}/transition', [ContractLifecycleController::class, 'transition'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.transition');
        Route::get('{kind}/{id}/history', [ContractLifecycleController::class, 'history'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.history');

        Route::get('{kind}/{id}/obligations', [ContractObligationController::class, 'forContract'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.obligations.contract');
        Route::post('{kind}/{id}/obligations', [ContractObligationController::class, 'store'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.obligations.store');

        Route::get('{kind}/{id}/parties', [ContractPartyController::class, 'index'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.parties.index');
        Route::post('{kind}/{id}/parties', [ContractPartyController::class, 'store'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.parties.store');
        Route::delete('{kind}/{id}/parties/{partyId}', [ContractPartyController::class, 'destroy'])->whereIn('kind', ['customer', 'supplier'])->whereUuid(['id', 'partyId'])->name('contracts.parties.destroy');

        Route::get('{kind}/{id}/documents', [ContractDocumentController::class, 'index'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.documents.index');
        Route::post('{kind}/{id}/documents', [ContractDocumentController::class, 'store'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.documents.store');
        Route::delete('{kind}/{id}/documents/{documentId}', [ContractDocumentController::class, 'destroy'])->whereIn('kind', ['customer', 'supplier'])->whereUuid(['id', 'documentId'])->name('contracts.documents.destroy');
    });
