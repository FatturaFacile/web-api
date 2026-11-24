<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ItalianVat\Http\Controllers\AnnualVatController;
use Modules\ItalianVat\Http\Controllers\CuController;
use Modules\ItalianVat\Http\Controllers\LipeController;
use Modules\ItalianVat\Http\Controllers\PlafondController;
use Modules\ItalianVat\Http\Controllers\ReceiptsController;
use Modules\ItalianVat\Http\Controllers\ThirdPartyGoodsController;
use Modules\ItalianVat\Http\Controllers\VatRegisterController;
use Modules\ItalianVat\Http\Controllers\VatSectionController;

// italian_vat: the accountants and the administrators keep the registers, print them definitively, communicate the periodic
// settlements, reconcile the annual VAT and issue the CU; the read-only accountant reads everything.
Route::prefix('api/v1/italian-vat')->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])->group(function (): void {
    // Simulations that save nothing: a POST that only needs read access (a member granted italian_vat.read can run them; the
    // read-only accountant role is stopped earlier by the tenant middleware, which refuses every non-safe method).
    Route::middleware('module.access:italian_vat,read')->group(function (): void {
        Route::post('receipts/ventilation/preview', [ReceiptsController::class, 'preview'])->name('italian-vat.receipts.preview');
        Route::post('plafond/check', [PlafondController::class, 'check'])->name('italian-vat.plafond.check');
    });

    Route::middleware('module.access:italian_vat')->group(function (): void {
        Route::get('sections', [VatSectionController::class, 'index'])->name('italian-vat.sections.index');
        Route::post('sections', [VatSectionController::class, 'store'])->name('italian-vat.sections.store');
        Route::put('sections/{id}', [VatSectionController::class, 'update'])->whereUuid('id')->name('italian-vat.sections.update');

        Route::get('registers/{sectionId}', [VatRegisterController::class, 'show'])->whereUuid('sectionId')->name('italian-vat.registers.show');
        Route::post('registers/{sectionId}/prints', [VatRegisterController::class, 'print'])->whereUuid('sectionId')->name('italian-vat.registers.print');
        Route::post('register-entries', [VatRegisterController::class, 'store'])->name('italian-vat.entries.store');
        Route::post('register-entries/{id}/reverse', [VatRegisterController::class, 'reverse'])->whereUuid('id')->name('italian-vat.entries.reverse');
        Route::delete('register-entries/{id}', [VatRegisterController::class, 'destroy'])->whereUuid('id')->name('italian-vat.entries.destroy');
        Route::get('register-prints', [VatRegisterController::class, 'prints'])->name('italian-vat.prints.index');
        Route::get('register-prints/{id}/verify', [VatRegisterController::class, 'verify'])->whereUuid('id')->name('italian-vat.prints.verify');
        Route::get('register-prints/{id}/pdf', [VatRegisterController::class, 'pdf'])->whereUuid('id')->name('italian-vat.prints.pdf');
        Route::get('pro-rata/{year}', [VatRegisterController::class, 'proRata'])->whereNumber('year')->name('italian-vat.pro-rata.show');
        Route::put('pro-rata/{year}', [VatRegisterController::class, 'saveProRata'])->whereNumber('year')->name('italian-vat.pro-rata.save');

        Route::post('receipts/ventilation', [ReceiptsController::class, 'ventilate'])->name('italian-vat.receipts.ventilate');
        Route::post('receipts/by-rate', [ReceiptsController::class, 'byRate'])->name('italian-vat.receipts.by-rate');

        Route::get('goods-movements', [ThirdPartyGoodsController::class, 'index'])->name('italian-vat.goods.index');
        Route::post('goods-movements', [ThirdPartyGoodsController::class, 'store'])->name('italian-vat.goods.store');
        Route::get('goods-balances', [ThirdPartyGoodsController::class, 'balances'])->name('italian-vat.goods.balances');

        Route::get('plafond/monitor', [PlafondController::class, 'monitor'])->name('italian-vat.plafond.monitor');
        Route::post('plafond/declarations/{id}/revoke', [PlafondController::class, 'revoke'])->whereUuid('id')->name('italian-vat.plafond.revoke');

        Route::get('lipe', [LipeController::class, 'index'])->name('italian-vat.lipe.index');
        Route::post('lipe', [LipeController::class, 'generate'])->name('italian-vat.lipe.generate');
        Route::get('lipe/{id}', [LipeController::class, 'show'])->whereUuid('id')->name('italian-vat.lipe.show');
        Route::get('lipe/{id}/xml', [LipeController::class, 'xml'])->whereUuid('id')->name('italian-vat.lipe.xml');
        Route::post('lipe/{id}/reconcile', [LipeController::class, 'reconcile'])->whereUuid('id')->name('italian-vat.lipe.reconcile');
        Route::post('lipe/{id}/signature', [LipeController::class, 'signature'])->whereUuid('id')->name('italian-vat.lipe.signature');
        Route::post('lipe/{id}/ready', [LipeController::class, 'ready'])->whereUuid('id')->name('italian-vat.lipe.ready');
        Route::post('lipe/{id}/transmission', [LipeController::class, 'transmission'])->whereUuid('id')->name('italian-vat.lipe.transmission');
        Route::post('lipe/{id}/rejection', [LipeController::class, 'rejection'])->whereUuid('id')->name('italian-vat.lipe.rejection');

        Route::get('annual-vat/{year}', [AnnualVatController::class, 'show'])->whereNumber('year')->name('italian-vat.annual.show');
        Route::post('annual-vat/{year}/calculate', [AnnualVatController::class, 'calculate'])->whereNumber('year')->name('italian-vat.annual.calculate');
        Route::post('annual-vat/{year}/finalize', [AnnualVatController::class, 'finalize'])->whereNumber('year')->name('italian-vat.annual.finalize');

        Route::get('cu/{year}', [CuController::class, 'index'])->whereNumber('year')->name('italian-vat.cu.index');
        Route::post('cu/{year}/build', [CuController::class, 'build'])->whereNumber('year')->name('italian-vat.cu.build');
        Route::post('cu/{year}/issue', [CuController::class, 'issue'])->whereNumber('year')->name('italian-vat.cu.issue');
        Route::get('cu/{year}/validation', [CuController::class, 'validation'])->whereNumber('year')->name('italian-vat.cu.validation');
        Route::get('cu/{year}/file', [CuController::class, 'file'])->whereNumber('year')->name('italian-vat.cu.file');
        Route::get('cu/certifications/{id}/pdf', [CuController::class, 'pdf'])->whereUuid('id')->name('italian-vat.cu.pdf');

        Route::get('f24/{year}', [CuController::class, 'f24'])->whereNumber('year')->name('italian-vat.f24.index');
        Route::post('f24/{year}/sync', [CuController::class, 'f24Sync'])->whereNumber('year')->name('italian-vat.f24.sync');
        Route::get('f24/{year}/reconciliation', [CuController::class, 'f24Reconciliation'])->whereNumber('year')->name('italian-vat.f24.reconciliation');
        Route::post('f24/payments/{id}/payments', [CuController::class, 'f24Pay'])->whereUuid('id')->name('italian-vat.f24.pay');
    });
});
