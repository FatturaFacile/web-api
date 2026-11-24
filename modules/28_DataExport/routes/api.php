<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\DataExport\Http\Controllers\AccountantExportController;
use Modules\DataExport\Http\Controllers\ExportDownloadController;
use Modules\DataExport\Http\Controllers\ExportJobController;
use Modules\DataExport\Http\Controllers\ExportScheduleController;
use Modules\DataExport\Http\Controllers\ReportingExportController;
use Modules\DataExport\Http\Controllers\SpreadsheetImportController;

Route::prefix('api/v1/exports')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:exports'])
    ->group(function (): void {
        Route::get('accountant-archive', AccountantExportController::class)->name('exports.accountant-archive');
        Route::get('csv/{dataset}', [ReportingExportController::class, 'csv'])->whereIn('dataset', ['invoices', 'purchases', 'payments', 'contacts'])->name('exports.csv');

        Route::get('jobs', [ExportJobController::class, 'index'])->name('exports.jobs.index');
        Route::post('jobs', [ExportJobController::class, 'store'])->name('exports.jobs.store');
        Route::get('jobs/{id}', [ExportJobController::class, 'show'])->whereUuid('id')->name('exports.jobs.show');
        Route::get('jobs/{id}/link', [ExportJobController::class, 'link'])->whereUuid('id')->name('exports.jobs.link');
        Route::post('jobs/{id}/retry', [ExportJobController::class, 'retry'])->whereUuid('id')->name('exports.jobs.retry');

        Route::post('imports/preview', [SpreadsheetImportController::class, 'preview'])->name('exports.imports.preview');
        Route::post('imports', [SpreadsheetImportController::class, 'store'])->name('exports.imports.store');

        Route::get('schedules', [ExportScheduleController::class, 'index'])->name('exports.schedules.index');
        Route::post('schedules', [ExportScheduleController::class, 'store'])->name('exports.schedules.store');
        Route::put('schedules/{id}', [ExportScheduleController::class, 'update'])->whereUuid('id')->name('exports.schedules.update');
        Route::delete('schedules/{id}', [ExportScheduleController::class, 'destroy'])->whereUuid('id')->name('exports.schedules.destroy');
    });

Route::prefix('api/v1/reporting')
    ->middleware(['api', 'api.key', 'locale', 'module.access:reporting'])
    ->group(function (): void {
        Route::get('feed/{dataset}', [ReportingExportController::class, 'feed'])->whereIn('dataset', ['invoices', 'purchases', 'payments', 'contacts'])->name('reporting.feed');
    });

// The signed, expiring link of a stored export: the signature is the credential, so no token and no tenant header. It lives
// outside the exports prefix on purpose: every route under it is behind module.access.
Route::prefix('api/v1/export-downloads')
    ->middleware(['api', 'throttle:30,1', 'signed'])
    ->group(function (): void {
        Route::get('{job}', ExportDownloadController::class)->whereUuid('job')->name('exports.download');
    });
