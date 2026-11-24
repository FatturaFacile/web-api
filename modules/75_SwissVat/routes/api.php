<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\SwissVat\Http\Controllers\BezugsteuerController;
use Modules\SwissVat\Http\Controllers\InputTaxAdjustmentController;
use Modules\SwissVat\Http\Controllers\SwissVatConfigController;
use Modules\SwissVat\Http\Controllers\SwissVatReturnController;
use Modules\SwissVat\Http\Controllers\SwissVatTaxCodeController;

/*
|--------------------------------------------------------------------------
| Swiss VAT Compliance (Mod 024 / Issue #164)
|--------------------------------------------------------------------------
| Effective & Saldosteuersatz reporting, Bezugsteuer detection, LIVA input
| tax adjustments, AFC box reconciliation, and SuisseTax XML export.
*/
Route::prefix('api/v1/swiss-vat')->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])->group(function (): void {
    Route::middleware('module.access:swiss_vat,read')->group(function (): void {
        Route::get('config', [SwissVatConfigController::class, 'show'])->name('swiss-vat.config.show');
        Route::get('tax-codes', [SwissVatTaxCodeController::class, 'index'])->name('swiss-vat.tax-codes.index');
        Route::get('sss-sectors', [SwissVatTaxCodeController::class, 'sssSectors'])->name('swiss-vat.sss-sectors.index');
        Route::get('bezugsteuer', [BezugsteuerController::class, 'index'])->name('swiss-vat.bezugsteuer.index');
        Route::get('adjustments', [InputTaxAdjustmentController::class, 'index'])->name('swiss-vat.adjustments.index');
        Route::get('returns', [SwissVatReturnController::class, 'index'])->name('swiss-vat.returns.index');
        Route::get('returns/{id}', [SwissVatReturnController::class, 'show'])->whereUuid('id')->name('swiss-vat.returns.show');
        Route::get('returns/{id}/reconciliation', [SwissVatReturnController::class, 'reconciliation'])->whereUuid('id')->name('swiss-vat.returns.reconciliation');
        Route::get('returns/{id}/exports', [SwissVatReturnController::class, 'exports'])->whereUuid('id')->name('swiss-vat.returns.exports');
        Route::get('exports/{id}/download', [SwissVatReturnController::class, 'downloadExport'])->whereUuid('id')->name('swiss-vat.exports.download');
    });

    Route::middleware('module.access:swiss_vat')->group(function (): void {
        Route::put('config', [SwissVatConfigController::class, 'update'])->name('swiss-vat.config.update');
        Route::post('tax-codes', [SwissVatTaxCodeController::class, 'store'])->name('swiss-vat.tax-codes.store');
        Route::put('tax-codes/{id}', [SwissVatTaxCodeController::class, 'update'])->whereUuid('id')->name('swiss-vat.tax-codes.update');

        Route::post('bezugsteuer', [BezugsteuerController::class, 'store'])->name('swiss-vat.bezugsteuer.store');
        Route::post('bezugsteuer/detect', [BezugsteuerController::class, 'detect'])->name('swiss-vat.bezugsteuer.detect');
        Route::post('bezugsteuer/{id}/confirm', [BezugsteuerController::class, 'confirm'])->whereUuid('id')->name('swiss-vat.bezugsteuer.confirm');
        Route::post('bezugsteuer/{id}/exclude', [BezugsteuerController::class, 'exclude'])->whereUuid('id')->name('swiss-vat.bezugsteuer.exclude');

        Route::post('adjustments', [InputTaxAdjustmentController::class, 'store'])->name('swiss-vat.adjustments.store');
        Route::put('adjustments/{id}', [InputTaxAdjustmentController::class, 'update'])->whereUuid('id')->name('swiss-vat.adjustments.update');
        Route::post('adjustments/{id}/approve', [InputTaxAdjustmentController::class, 'approve'])->whereUuid('id')->name('swiss-vat.adjustments.approve');
        Route::delete('adjustments/{id}', [InputTaxAdjustmentController::class, 'destroy'])->whereUuid('id')->name('swiss-vat.adjustments.destroy');

        Route::post('returns/calculate', [SwissVatReturnController::class, 'calculate'])->name('swiss-vat.returns.calculate');
        Route::post('returns/{id}/validate', [SwissVatReturnController::class, 'validateXml'])->whereUuid('id')->name('swiss-vat.returns.validate');
        Route::post('returns/{id}/finalize', [SwissVatReturnController::class, 'finalize'])->whereUuid('id')->name('swiss-vat.returns.finalize');
        Route::post('returns/{id}/export-xml', [SwissVatReturnController::class, 'exportXml'])->whereUuid('id')->name('swiss-vat.returns.export-xml');
    });
});
