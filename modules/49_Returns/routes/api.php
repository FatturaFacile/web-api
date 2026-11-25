<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Returns\Http\Controllers\AdvanceReplacementController;
use Modules\Returns\Http\Controllers\CustomerReturnController;
use Modules\Returns\Http\Controllers\ReturnGradingController;
use Modules\Returns\Http\Controllers\ReverseLogisticsConfigController;
use Modules\Returns\Http\Controllers\RmaController;
use Modules\Returns\Http\Controllers\SettingsController;
use Modules\Returns\Http\Controllers\SummaryController;
use Modules\Returns\Http\Controllers\SupplierReturnController;
use Modules\Returns\Http\Controllers\WarrantyClaimController;
use Modules\Returns\Http\Controllers\WarrantyController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Returns and warranty (RMA). module.access:returns lets every member read (GET) and only the roles with write access of
| config/permissions.php change anything; the policy keeps the records inside the company and reserves authorising or
| rejecting a return, resolving it, overriding the return window and deciding a warranty claim to owners,
| administrators and members granted returns.approve.
*/
Route::prefix('api/v1/returns')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:returns'])
    ->name('api.v1.returns.')
    ->where(['id' => $uuid])
    ->group(function (): void {
        Route::get('/summary', [SummaryController::class, 'show'])->name('summary');

        Route::get('/settings', [SettingsController::class, 'show'])->name('settings.show');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::get('/reason-codes', [SettingsController::class, 'reasons'])->name('reason-codes.index');
        Route::post('/reason-codes', [SettingsController::class, 'storeReason'])->name('reason-codes.store');
        Route::put('/reason-codes/{id}', [SettingsController::class, 'updateReason'])->name('reason-codes.update');
        Route::delete('/reason-codes/{id}', [SettingsController::class, 'destroyReason'])->name('reason-codes.destroy');

        Route::get('/sources', [RmaController::class, 'sources'])->name('sources');
        Route::get('/rmas', [RmaController::class, 'index'])->name('rmas.index');
        Route::post('/rmas', [RmaController::class, 'store'])->name('rmas.store');
        Route::get('/rmas/{id}', [RmaController::class, 'show'])->name('rmas.show');
        Route::put('/rmas/{id}', [RmaController::class, 'update'])->name('rmas.update');
        Route::post('/rmas/{id}/approve', [RmaController::class, 'approve'])->name('rmas.approve');
        Route::post('/rmas/{id}/reject', [RmaController::class, 'reject'])->name('rmas.reject');
        Route::post('/rmas/{id}/receive', [RmaController::class, 'receive'])->name('rmas.receive');
        Route::post('/rmas/{id}/inspect', [RmaController::class, 'inspect'])->name('rmas.inspect');
        Route::post('/rmas/{id}/resolve', [RmaController::class, 'resolve'])->name('rmas.resolve');
        Route::post('/rmas/{id}/close', [RmaController::class, 'close'])->name('rmas.close');

        Route::get('/warranties', [WarrantyController::class, 'index'])->name('warranties.index');
        Route::post('/warranties', [WarrantyController::class, 'store'])->name('warranties.store');
        Route::get('/warranties/{id}', [WarrantyController::class, 'show'])->name('warranties.show');
        Route::put('/warranties/{id}', [WarrantyController::class, 'update'])->name('warranties.update');
        Route::post('/warranties/{id}/void', [WarrantyController::class, 'void'])->name('warranties.void');
        Route::get('/warranties/{id}/validity', [WarrantyController::class, 'validity'])->name('warranties.validity');

        Route::get('/claims', [WarrantyClaimController::class, 'index'])->name('claims.index');
        Route::post('/claims', [WarrantyClaimController::class, 'store'])->name('claims.store');
        Route::get('/claims/{id}', [WarrantyClaimController::class, 'show'])->name('claims.show');
        Route::post('/claims/{id}/accept', [WarrantyClaimController::class, 'accept'])->name('claims.accept');
        Route::post('/claims/{id}/reject', [WarrantyClaimController::class, 'reject'])->name('claims.reject');
        Route::post('/claims/{id}/resolve', [WarrantyClaimController::class, 'resolve'])->name('claims.resolve');

        Route::get('/supplier-returns', [SupplierReturnController::class, 'index'])->name('supplier-returns.index');
        Route::post('/supplier-returns', [SupplierReturnController::class, 'store'])->name('supplier-returns.store');
        Route::get('/supplier-returns/{id}', [SupplierReturnController::class, 'show'])->name('supplier-returns.show');
        Route::put('/supplier-returns/{id}', [SupplierReturnController::class, 'update'])->name('supplier-returns.update');
        Route::post('/supplier-returns/{id}/ship', [SupplierReturnController::class, 'ship'])->name('supplier-returns.ship');
        Route::post('/supplier-returns/{id}/credit', [SupplierReturnController::class, 'credit'])->name('supplier-returns.credit');
        Route::post('/supplier-returns/{id}/close', [SupplierReturnController::class, 'close'])->name('supplier-returns.close');
        Route::post('/supplier-returns/{id}/cancel', [SupplierReturnController::class, 'cancel'])->name('supplier-returns.cancel');

        // Mod 232: Reverse Logistics, Advance Replacements, Grading/B-Stock & Credit Note VAT Time-Limit Control
        Route::get('/reverse-logistics/config', [ReverseLogisticsConfigController::class, 'show'])->name('reverse-logistics.config');
        Route::post('/eligibility-check', [CustomerReturnController::class, 'eligibilityCheck'])->name('eligibility-check');

        Route::get('/customer-returns', [CustomerReturnController::class, 'index'])->name('customer-returns.index');
        Route::post('/customer-returns', [CustomerReturnController::class, 'store'])->name('customer-returns.store');
        Route::get('/customer-returns/{id}', [CustomerReturnController::class, 'show'])->name('customer-returns.show');
        Route::post('/customer-returns/{id}/approve', [CustomerReturnController::class, 'approve'])->name('customer-returns.approve');
        Route::post('/customer-returns/{id}/receive', [CustomerReturnController::class, 'receive'])->name('customer-returns.receive');
        Route::get('/customer-returns/{id}/label', [CustomerReturnController::class, 'label'])->name('customer-returns.label');

        Route::post('/credit-note-check', [CustomerReturnController::class, 'creditNoteCheck'])->name('credit-note-check');
        Route::post('/credit-notes', [CustomerReturnController::class, 'issueCreditNote'])->name('credit-notes.store');

        Route::get('/advance-replacements', [AdvanceReplacementController::class, 'index'])->name('advance-replacements.index');
        Route::post('/advance-replacements', [AdvanceReplacementController::class, 'store'])->name('advance-replacements.store');
        Route::post('/advance-replacements/scan-overdue', [AdvanceReplacementController::class, 'scanOverdue'])->name('advance-replacements.scan-overdue');
        Route::post('/advance-replacements/{id}/receive-defective', [AdvanceReplacementController::class, 'receiveDefective'])->name('advance-replacements.receive-defective');
        Route::post('/advance-replacements/{id}/bill-overdue', [AdvanceReplacementController::class, 'billOverdue'])->name('advance-replacements.bill-overdue');

        Route::get('/grading-inspections', [ReturnGradingController::class, 'indexInspections'])->name('grading-inspections.index');
        Route::post('/grading-inspections', [ReturnGradingController::class, 'storeInspection'])->name('grading-inspections.store');
        Route::get('/refurbished-items', [ReturnGradingController::class, 'indexRefurbished'])->name('refurbished-items.index');
        Route::post('/refurbished-items/{id}/complete-work-order', [ReturnGradingController::class, 'completeWorkOrder'])->name('refurbished-items.complete-work-order');
    });
