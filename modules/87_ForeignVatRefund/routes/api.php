<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ForeignVatRefund\Http\Controllers\ClaimController;
use Modules\ForeignVatRefund\Http\Controllers\DashboardController;
use Modules\ForeignVatRefund\Http\Controllers\LedgerController;
use Modules\ForeignVatRefund\Http\Controllers\MandateController;
use Modules\ForeignVatRefund\Http\Controllers\RuleController;

/*
|--------------------------------------------------------------------------
| Foreign VAT refund: EU portal (Dir. 2008/9/EC), ESTV reciprocity (forms 1222/1223), Art. 38-ter IT (Modello IVA 79) (Mod 227 / Issue #473)
|--------------------------------------------------------------------------
*/
Route::prefix('api/v1/foreign-vat-refund')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:foreign_vat_refund'])
    ->group(function (): void {
        Route::get('dashboard', [DashboardController::class, 'dashboard'])->name('foreign-vat-refund.dashboard');
        Route::get('reference', [DashboardController::class, 'reference'])->name('foreign-vat-refund.reference');
        Route::post('preview-split', [DashboardController::class, 'previewSplit'])->name('foreign-vat-refund.preview-split');
        Route::get('settings', [DashboardController::class, 'showSettings'])->name('foreign-vat-refund.settings.show');
        Route::put('settings', [DashboardController::class, 'updateSettings'])->name('foreign-vat-refund.settings.update');

        Route::get('ledger', [LedgerController::class, 'index'])->name('foreign-vat-refund.ledger.index');
        Route::post('ledger', [LedgerController::class, 'store'])->name('foreign-vat-refund.ledger.store');
        Route::get('ledger/{id}', [LedgerController::class, 'show'])->whereUuid('id')->name('foreign-vat-refund.ledger.show');
        Route::patch('ledger/{id}', [LedgerController::class, 'update'])->whereUuid('id')->name('foreign-vat-refund.ledger.update');
        Route::delete('ledger/{id}', [LedgerController::class, 'destroy'])->whereUuid('id')->name('foreign-vat-refund.ledger.destroy');
        Route::post('ledger/{id}/recalculate', [LedgerController::class, 'recalculate'])->whereUuid('id')->name('foreign-vat-refund.ledger.recalculate');
        Route::post('ledger/{id}/attachment', [LedgerController::class, 'uploadAttachment'])->whereUuid('id')->name('foreign-vat-refund.ledger.attachment.upload');
        Route::get('ledger/{id}/attachment', [LedgerController::class, 'downloadAttachment'])->whereUuid('id')->name('foreign-vat-refund.ledger.attachment.download');

        Route::get('claims', [ClaimController::class, 'index'])->name('foreign-vat-refund.claims.index');
        Route::post('claims/preview', [ClaimController::class, 'preview'])->name('foreign-vat-refund.claims.preview');
        Route::post('claims', [ClaimController::class, 'store'])->name('foreign-vat-refund.claims.store');
        Route::get('claims/{id}', [ClaimController::class, 'show'])->whereUuid('id')->name('foreign-vat-refund.claims.show');
        Route::delete('claims/{id}', [ClaimController::class, 'destroy'])->whereUuid('id')->name('foreign-vat-refund.claims.destroy');
        Route::post('claims/{id}/ready', [ClaimController::class, 'ready'])->whereUuid('id')->name('foreign-vat-refund.claims.ready');
        Route::post('claims/{id}/draft', [ClaimController::class, 'draft'])->whereUuid('id')->name('foreign-vat-refund.claims.draft');
        Route::post('claims/{id}/submit', [ClaimController::class, 'submit'])->whereUuid('id')->name('foreign-vat-refund.claims.submit');
        Route::post('claims/{id}/request-information', [ClaimController::class, 'requestInformation'])->whereUuid('id')->name('foreign-vat-refund.claims.request-information');
        Route::post('claims/{id}/decide', [ClaimController::class, 'decide'])->whereUuid('id')->name('foreign-vat-refund.claims.decide');
        Route::post('claims/{id}/reconcile', [ClaimController::class, 'reconcile'])->whereUuid('id')->name('foreign-vat-refund.claims.reconcile');
        Route::get('claims/{id}/exports/{type}', [ClaimController::class, 'export'])->whereUuid('id')->whereIn('type', ['eu-xml', 'estv-package', 'iva79-package'])->name('foreign-vat-refund.claims.export');

        Route::get('rules', [RuleController::class, 'index'])->name('foreign-vat-refund.rules.index');
        Route::post('rules', [RuleController::class, 'store'])->name('foreign-vat-refund.rules.store');
        Route::put('rules/{id}', [RuleController::class, 'update'])->whereUuid('id')->name('foreign-vat-refund.rules.update');
        Route::delete('rules/{id}', [RuleController::class, 'destroy'])->whereUuid('id')->name('foreign-vat-refund.rules.destroy');

        Route::get('mandates', [MandateController::class, 'index'])->name('foreign-vat-refund.mandates.index');
        Route::post('mandates', [MandateController::class, 'store'])->name('foreign-vat-refund.mandates.store');
        Route::get('mandates/{id}', [MandateController::class, 'show'])->whereUuid('id')->name('foreign-vat-refund.mandates.show');
        Route::put('mandates/{id}', [MandateController::class, 'update'])->whereUuid('id')->name('foreign-vat-refund.mandates.update');
        Route::delete('mandates/{id}', [MandateController::class, 'destroy'])->whereUuid('id')->name('foreign-vat-refund.mandates.destroy');
        Route::post('mandates/{id}/documents', [MandateController::class, 'uploadDocument'])->whereUuid('id')->name('foreign-vat-refund.mandates.documents.upload');
        Route::get('mandates/{id}/documents/{type}', [MandateController::class, 'downloadDocument'])->whereUuid('id')->whereIn('type', ['power_of_attorney', 'certificate'])->name('foreign-vat-refund.mandates.documents.download');
    });
