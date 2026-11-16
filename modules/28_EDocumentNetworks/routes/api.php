<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\EDocumentNetworks\Http\Controllers\EDocumentNetworkController;
use Modules\EDocumentNetworks\Http\Controllers\PeppolInvoiceController;

Route::prefix('api/v1/edocument-networks')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:edocument_networks'])
    ->group(function (): void {
        Route::get('/', [EDocumentNetworkController::class, 'index'])->name('edocument-networks.index');
        Route::get('transmissions', [EDocumentNetworkController::class, 'transmissions'])->name('edocument-networks.transmissions');
        Route::get('transmissions/{id}', [EDocumentNetworkController::class, 'show'])->whereUuid('id')->name('edocument-networks.transmissions.show');
        Route::post('transmissions/{id}/retry', [EDocumentNetworkController::class, 'retry'])->whereUuid('id')->name('edocument-networks.transmissions.retry');
        Route::get('peppol/invoices/{id}/ubl', [PeppolInvoiceController::class, 'ubl'])->whereUuid('id')->name('edocument-networks.peppol.ubl');
        Route::post('{network}/transmissions', [EDocumentNetworkController::class, 'send'])->whereIn('network', ['ebill', 'peppol', 'nso'])->name('edocument-networks.send');
    });
