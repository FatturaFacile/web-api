<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\DocumentTrust\Http\Controllers\DocumentTrustController;
use Modules\DocumentTrust\Http\Controllers\PreservationController;
use Modules\DocumentTrust\Http\Controllers\PreservationDisposalController;
use Modules\DocumentTrust\Http\Controllers\PreservationSealController;

Route::prefix('api/v1/document-trust')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:document_trust'])
    ->group(function (): void {
        Route::post('signatures', [DocumentTrustController::class, 'sign'])->name('document-trust.sign');
        Route::get('signatures', [DocumentTrustController::class, 'signatures'])->name('document-trust.signatures');
        Route::post('signatures/{id}/verify', [DocumentTrustController::class, 'verify'])->whereUuid('id')->name('document-trust.verify');
        Route::post('preservation', [DocumentTrustController::class, 'preserve'])->name('document-trust.preserve');
        Route::get('preservation', [DocumentTrustController::class, 'preservationRecords'])->name('document-trust.preservation');
    });

Route::prefix('api/v1/document-trust/settings')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:integrations'])
    ->group(function (): void {
        Route::get('/', [DocumentTrustController::class, 'settings'])->name('document-trust.settings.show');
        Route::put('/', [DocumentTrustController::class, 'updateSettings'])->name('document-trust.settings.update');
    });

Route::prefix('api/v1/document-trust')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:document_trust'])
    ->group(function (): void {
        Route::get('preservation-packages', [PreservationController::class, 'index'])->name('document-trust.preservation-packages.index');
        Route::post('preservation-packages', [PreservationController::class, 'store'])->name('document-trust.preservation-packages.store');
        Route::get('preservation-packages/{id}', [PreservationController::class, 'show'])->whereUuid('id')->name('document-trust.preservation-packages.show');
        Route::delete('preservation-packages/{id}', [PreservationController::class, 'destroy'])->whereUuid('id')->name('document-trust.preservation-packages.destroy');
        Route::post('preservation-packages/{id}/items', [PreservationController::class, 'addItem'])->whereUuid('id')->name('document-trust.preservation-packages.items.store');
        Route::delete('preservation-packages/{id}/items/{itemId}', [PreservationController::class, 'removeItem'])->whereUuid(['id', 'itemId'])->name('document-trust.preservation-packages.items.destroy');
        Route::post('preservation-packages/{id}/close', [PreservationController::class, 'close'])->whereUuid('id')->name('document-trust.preservation-packages.close');
        Route::post('preservation-packages/{id}/verify', [PreservationController::class, 'verify'])->whereUuid('id')->name('document-trust.preservation-packages.verify');
        Route::get('preservation-packages/{id}/export', [PreservationController::class, 'export'])->whereUuid('id')->name('document-trust.preservation-packages.export');
        Route::get('preservation-items', [PreservationController::class, 'search'])->name('document-trust.preservation-items.search');
        Route::get('preservation-settings', [PreservationController::class, 'settings'])->name('document-trust.preservation-settings.show');
        Route::put('preservation-settings', [PreservationController::class, 'updateSettings'])->name('document-trust.preservation-settings.update');
        Route::get('preservation-access-logs', [PreservationController::class, 'accessLogs'])->name('document-trust.preservation-access-logs.index');
    });

Route::prefix('api/v1/document-trust')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:document_trust'])
    ->group(function (): void {
        Route::get('preservation-disposal-candidates', [PreservationDisposalController::class, 'candidates'])->name('document-trust.preservation-disposal.candidates');
        Route::get('preservation-disposal-proposals', [PreservationDisposalController::class, 'proposals'])->name('document-trust.preservation-disposal.proposals');
        Route::post('preservation-disposal-proposals', [PreservationDisposalController::class, 'propose'])->name('document-trust.preservation-disposal.propose');
        Route::post('preservation-disposal-proposals/{id}/approve', [PreservationDisposalController::class, 'approve'])->whereUuid('id')->name('document-trust.preservation-disposal.approve');
        Route::post('preservation-disposal-proposals/{id}/reject', [PreservationDisposalController::class, 'reject'])->whereUuid('id')->name('document-trust.preservation-disposal.reject');
        Route::post('preservation-disposal-proposals/{id}/execute', [PreservationDisposalController::class, 'execute'])->whereUuid('id')->name('document-trust.preservation-disposal.execute');
        Route::get('preservation-disposals', [PreservationDisposalController::class, 'index'])->name('document-trust.preservation-disposal.index');
        Route::post('preservation-packages/{id}/legal-hold', [PreservationDisposalController::class, 'placeHold'])->whereUuid('id')->name('document-trust.preservation-packages.hold.place');
        Route::delete('preservation-packages/{id}/legal-hold', [PreservationDisposalController::class, 'releaseHold'])->whereUuid('id')->name('document-trust.preservation-packages.hold.release');
    });

Route::prefix('api/v1/document-trust/preservation-seal')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:document_trust'])
    ->group(function (): void {
        Route::post('journal-book', [PreservationSealController::class, 'journalBook'])->middleware('module.access:ledger')->name('document-trust.preservation-seal.journal-book');
        Route::post('payslips', [PreservationSealController::class, 'payslips'])->middleware('module.access:payroll')->name('document-trust.preservation-seal.payslips');
    });
