<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\FiduciaryPortal\Http\Controllers\FiduciaryDossierController;
use Modules\FiduciaryPortal\Http\Controllers\FiduciaryEntryNoteController;
use Modules\FiduciaryPortal\Http\Controllers\FiduciaryExportController;
use Modules\FiduciaryPortal\Http\Controllers\FiduciaryJournalController;
use Modules\FiduciaryPortal\Http\Controllers\FiduciaryPeriodLockController;
use Modules\FiduciaryPortal\Http\Controllers\FiduciaryTranscodingController;
use Modules\FiduciaryPortal\Http\Controllers\FiduciaryWorkspaceController;

/*
|--------------------------------------------------------------------------
| External Accountant & Fiduciary Portal (Mod 032 / Issue #336)
|--------------------------------------------------------------------------
*/
Route::prefix('api/v1/fiduciary-portal')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:fiduciary_portal'])
    ->group(function (): void {
        Route::get('overview', [FiduciaryWorkspaceController::class, 'overview'])->name('fiduciary-portal.overview');
        Route::get('audit-logs', [FiduciaryWorkspaceController::class, 'auditLogs'])->name('fiduciary-portal.audit-logs');

        Route::get('journals', [FiduciaryJournalController::class, 'index'])->name('fiduciary-portal.journals.index');
        Route::post('journals/verify', [FiduciaryJournalController::class, 'verify'])->name('fiduciary-portal.journals.verify');
        Route::get('account-ledger', [FiduciaryJournalController::class, 'accountLedger'])->name('fiduciary-portal.account-ledger');

        Route::get('transcodings', [FiduciaryTranscodingController::class, 'index'])->name('fiduciary-portal.transcodings.index');
        Route::post('transcodings', [FiduciaryTranscodingController::class, 'store'])->name('fiduciary-portal.transcodings.store');
        Route::post('transcodings/bulk', [FiduciaryTranscodingController::class, 'bulkUpsert'])->name('fiduciary-portal.transcodings.bulk');
        Route::post('transcodings/seed-defaults', [FiduciaryTranscodingController::class, 'seedDefaults'])->name('fiduciary-portal.transcodings.seed-defaults');
        Route::delete('transcodings/{id}', [FiduciaryTranscodingController::class, 'destroy'])->whereUuid('id')->name('fiduciary-portal.transcodings.destroy');

        Route::get('periods', [FiduciaryPeriodLockController::class, 'index'])->name('fiduciary-portal.periods.index');
        Route::post('periods/{id}/lock', [FiduciaryPeriodLockController::class, 'lock'])->whereUuid('id')->name('fiduciary-portal.periods.lock');
        Route::post('periods/{id}/unlock', [FiduciaryPeriodLockController::class, 'unlock'])->whereUuid('id')->name('fiduciary-portal.periods.unlock');

        Route::get('notes', [FiduciaryEntryNoteController::class, 'index'])->name('fiduciary-portal.notes.index');
        Route::post('notes', [FiduciaryEntryNoteController::class, 'store'])->name('fiduciary-portal.notes.store');
        Route::post('notes/{id}/resolve', [FiduciaryEntryNoteController::class, 'resolve'])->whereUuid('id')->name('fiduciary-portal.notes.resolve');

        Route::get('exports', [FiduciaryExportController::class, 'index'])->name('fiduciary-portal.exports.index');
        Route::post('exports/validate', [FiduciaryExportController::class, 'validateExport'])->name('fiduciary-portal.exports.validate');
        Route::post('exports', [FiduciaryExportController::class, 'generate'])->name('fiduciary-portal.exports.generate');
        Route::get('exports/{id}/download', [FiduciaryExportController::class, 'download'])->whereUuid('id')->name('fiduciary-portal.exports.download');

        Route::get('dossier/preview', [FiduciaryDossierController::class, 'preview'])->name('fiduciary-portal.dossier.preview');
        Route::get('dossier/download', [FiduciaryDossierController::class, 'download'])->name('fiduciary-portal.dossier.download');
    });
