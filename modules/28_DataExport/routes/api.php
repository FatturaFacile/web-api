<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\DataExport\Http\Controllers\AccountantExportController;
use Modules\DataExport\Http\Controllers\ReportingExportController;

Route::prefix('api/v1/exports')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:exports'])
    ->group(function (): void {
        Route::get('accountant-archive', AccountantExportController::class)->name('exports.accountant-archive');
        Route::get('csv/{dataset}', [ReportingExportController::class, 'csv'])->whereIn('dataset', ['invoices', 'purchases', 'payments', 'contacts'])->name('exports.csv');
    });

Route::prefix('api/v1/reporting')
    ->middleware(['api', 'api.key', 'locale', 'module.access:reporting'])
    ->group(function (): void {
        Route::get('feed/{dataset}', [ReportingExportController::class, 'feed'])->whereIn('dataset', ['invoices', 'purchases', 'payments', 'contacts'])->name('reporting.feed');
    });
