<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\SwissFiscal\Presentation\Http\Controllers\SwissFiscalController;

// swiss_fiscal: members of the sales and finance roles issue QR-bills, fiscal documents and read bank notifications.
Route::prefix('api/v1/swiss-fiscal')->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:swiss_fiscal'])->group(function (): void {
    Route::get('vat-rates', [SwissFiscalController::class, 'vatRates'])->name('swiss-fiscal.vat-rates');
    Route::post('qr-bills', [SwissFiscalController::class, 'qrBill'])->name('swiss-fiscal.qr-bills');
    Route::post('fiscal-documents', [SwissFiscalController::class, 'fiscalDocument'])->name('swiss-fiscal.fiscal-documents');
    Route::post('camt054', [SwissFiscalController::class, 'camt054'])->name('swiss-fiscal.camt054');
});
