<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\DownPayments\Http\Controllers\DepositController;
use Modules\DownPayments\Http\Controllers\DownPaymentChainController;
use Modules\DownPayments\Http\Controllers\SettlementController;

/*
|--------------------------------------------------------------------------
| Catena acconti, caparre e depositi cauzionali (Mod 203 / Issue #449)
|--------------------------------------------------------------------------
| Down-payment chains of orders, quotes and contracts: the installment plan wizard, advance invoices (TD02) and pro-forma requests,
| earnest money and security deposits with their resolution wizard, the final settlement invoice with the multi-rate deduction of
| the advances and the credit notes (TD04) of the advances.
*/
Route::prefix('api/v1/down-payment-chains')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:down_payments'])
    ->group(function (): void {
        Route::get('lookups', [DownPaymentChainController::class, 'lookups'])->name('down-payments.lookups');
        Route::post('preview-plan', [DownPaymentChainController::class, 'previewPlan'])->name('down-payments.preview-plan');
        Route::post('from-source', [DownPaymentChainController::class, 'store'])->name('down-payments.store');
        Route::get('/', [DownPaymentChainController::class, 'index'])->name('down-payments.index');
        Route::get('{id}', [DownPaymentChainController::class, 'show'])->whereUuid('id')->name('down-payments.show');
        Route::post('{id}/cancel', [DownPaymentChainController::class, 'cancel'])->whereUuid('id')->name('down-payments.cancel');
        Route::post('{id}/installments/{installmentId}/emit', [DownPaymentChainController::class, 'emit'])->whereUuid(['id', 'installmentId'])->name('down-payments.installments.emit');

        Route::post('{id}/proforma/{linkId}/confirm-payment', [SettlementController::class, 'confirmProforma'])->whereUuid(['id', 'linkId'])->name('down-payments.proforma.confirm-payment');
        Route::post('{id}/preview-final-settlement', [SettlementController::class, 'preview'])->whereUuid('id')->name('down-payments.final-settlement.preview');
        Route::post('{id}/generate-final-settlement', [SettlementController::class, 'generate'])->whereUuid('id')->name('down-payments.final-settlement.generate');
        Route::post('{id}/links/{linkId}/credit-note/preview', [SettlementController::class, 'previewCreditNote'])->whereUuid(['id', 'linkId'])->name('down-payments.credit-note.preview');
        Route::post('{id}/links/{linkId}/credit-note', [SettlementController::class, 'creditNote'])->whereUuid(['id', 'linkId'])->name('down-payments.credit-note');

        Route::post('{id}/deposits/{depositId}/confirm-payment', [DepositController::class, 'confirmPayment'])->whereUuid(['id', 'depositId'])->name('down-payments.deposits.confirm-payment');
        Route::post('{id}/deposits/{depositId}/preview-resolution', [DepositController::class, 'preview'])->whereUuid(['id', 'depositId'])->name('down-payments.deposits.preview');
        Route::post('{id}/deposits/{depositId}/resolve', [DepositController::class, 'resolve'])->whereUuid(['id', 'depositId'])->name('down-payments.deposits.resolve');
    });
