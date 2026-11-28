<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\WithholdingRecipients\Http\Controllers\AnnualReturnController;
use Modules\WithholdingRecipients\Http\Controllers\CertificateController;
use Modules\WithholdingRecipients\Http\Controllers\CompensationController;
use Modules\WithholdingRecipients\Http\Controllers\DashboardController;
use Modules\WithholdingRecipients\Http\Controllers\InlNotificationController;
use Modules\WithholdingRecipients\Http\Controllers\LookupController;
use Modules\WithholdingRecipients\Http\Controllers\RecipientController;
use Modules\WithholdingRecipients\Http\Controllers\SettlementController;

/*
|--------------------------------------------------------------------------
| Withholding taxes on commissions, royalties, occasional work and amateur sports (Mod 356 / Issue #602)
|--------------------------------------------------------------------------
| Recipient registry (IT / CH), per-regime tax engines, preventive INL communication, cash-basis settlement with F24 and
| UniEmens flows, annual CU / AVS records.
*/
Route::prefix('api/v1/withholding-recipients')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:withholding_recipients'])
    ->group(function (): void {
        Route::get('lookups', [LookupController::class, 'index'])->name('withholding-recipients.lookups');
        Route::get('dashboard', [DashboardController::class, 'index'])->name('withholding-recipients.dashboard');

        Route::get('recipients', [RecipientController::class, 'index'])->name('withholding-recipients.recipients.index');
        Route::post('recipients', [RecipientController::class, 'store'])->name('withholding-recipients.recipients.store');
        Route::get('recipients/{id}', [RecipientController::class, 'show'])->whereUuid('id')->name('withholding-recipients.recipients.show');
        Route::put('recipients/{id}', [RecipientController::class, 'update'])->whereUuid('id')->name('withholding-recipients.recipients.update');
        Route::delete('recipients/{id}', [RecipientController::class, 'destroy'])->whereUuid('id')->name('withholding-recipients.recipients.destroy');
        Route::get('recipients/{id}/thresholds', [DashboardController::class, 'thresholds'])->whereUuid('id')->name('withholding-recipients.recipients.thresholds');

        Route::get('recipients/{id}/certificates', [CertificateController::class, 'index'])->whereUuid('id')->name('withholding-recipients.certificates.index');
        Route::post('recipients/{id}/certificates', [CertificateController::class, 'store'])->whereUuid('id')->name('withholding-recipients.certificates.store');
        Route::delete('certificates/{id}', [CertificateController::class, 'destroy'])->whereUuid('id')->name('withholding-recipients.certificates.destroy');

        Route::get('recipients/{id}/inl-notifications', [InlNotificationController::class, 'index'])->whereUuid('id')->name('withholding-recipients.inl.index');
        Route::post('recipients/{id}/inl-notifications', [InlNotificationController::class, 'store'])->whereUuid('id')->name('withholding-recipients.inl.store');
        Route::post('inl-notifications/{id}/mark-sent', [InlNotificationController::class, 'markSent'])->whereUuid('id')->name('withholding-recipients.inl.mark-sent');
        Route::post('inl-notifications/{id}/cancel', [InlNotificationController::class, 'cancel'])->whereUuid('id')->name('withholding-recipients.inl.cancel');
        Route::get('inl-notifications/{id}/download', [InlNotificationController::class, 'download'])->whereUuid('id')->name('withholding-recipients.inl.download');

        Route::get('settlements', [SettlementController::class, 'index'])->name('withholding-recipients.settlements.index');
        Route::post('settlements', [SettlementController::class, 'store'])->name('withholding-recipients.settlements.store');
        Route::post('settlements/batch', [SettlementController::class, 'batch'])->name('withholding-recipients.settlements.batch');
        Route::get('settlements/{id}', [SettlementController::class, 'show'])->whereUuid('id')->name('withholding-recipients.settlements.show');

        Route::get('flows/f24', [SettlementController::class, 'f24'])->name('withholding-recipients.flows.f24');
        Route::get('flows/f24/export', [SettlementController::class, 'f24Export'])->name('withholding-recipients.flows.f24.export');
        Route::get('flows/uniemens', [SettlementController::class, 'uniEmens'])->name('withholding-recipients.flows.uniemens');

        Route::get('annual-returns', [AnnualReturnController::class, 'index'])->name('withholding-recipients.annual-returns.index');
        Route::post('annual-returns/generate', [AnnualReturnController::class, 'generate'])->name('withholding-recipients.annual-returns.generate');
        Route::get('annual-returns/export', [AnnualReturnController::class, 'export'])->name('withholding-recipients.annual-returns.export');
    });

// A simulation saves nothing: it needs read access only, even though it is a POST.
Route::prefix('api/v1/withholding-recipients')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:withholding_recipients,read'])
    ->group(function (): void {
        Route::post('compensations/simulate', [CompensationController::class, 'simulate'])->name('withholding-recipients.compensations.simulate');
    });
