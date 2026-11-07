<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Contracts\Http\Controllers\ContractRegisterController;

Route::prefix('api/v1/contract-register')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:contracts'])
    ->group(function (): void {
        Route::get('/', [ContractRegisterController::class, 'index'])->name('contracts.index');
        Route::post('/', [ContractRegisterController::class, 'store'])->name('contracts.store');
        Route::get('alerts', [ContractRegisterController::class, 'alerts'])->name('contracts.alerts');
        Route::post('alerts/{id}/acknowledge', [ContractRegisterController::class, 'acknowledgeAlert'])->whereUuid('id')->name('contracts.alerts.acknowledge');
        Route::get('{kind}/{id}', [ContractRegisterController::class, 'show'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.show');
        Route::put('{kind}/{id}/terms', [ContractRegisterController::class, 'updateTerms'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.terms');
        Route::post('{kind}/{id}/renew', [ContractRegisterController::class, 'renew'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.renew');
        Route::post('{kind}/{id}/notice', [ContractRegisterController::class, 'giveNotice'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.notice');
    });
