<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\LeaseAccounting\Http\Controllers\LeaseContractController;
use Modules\LeaseAccounting\Http\Controllers\LeasePostingController;
use Modules\LeaseAccounting\Http\Controllers\LeaseReportController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Lease accounting (IFRS 16, OIC, Swiss GAAP FER). module.access:leases lets finance roles read (GET) and only owners,
| administrators and accountants change anything; the policy keeps the records inside the company and reserves the
| measurement and the ledger postings to owners, administrators, accountants and members granted leases.post.
*/
Route::prefix('api/v1/leases')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:leases'])
    ->name('api.v1.leases.')
    ->where(['id' => $uuid])
    ->group(function (): void {
        Route::get('/contracts', [LeaseContractController::class, 'index'])->name('contracts.index');
        Route::post('/contracts', [LeaseContractController::class, 'store'])->name('contracts.store');
        Route::get('/contracts/{id}', [LeaseContractController::class, 'show'])->name('contracts.show');
        Route::put('/contracts/{id}', [LeaseContractController::class, 'update'])->name('contracts.update');
        Route::delete('/contracts/{id}', [LeaseContractController::class, 'destroy'])->name('contracts.destroy');
        Route::get('/contracts/{id}/preview', [LeaseContractController::class, 'preview'])->name('contracts.preview');
        Route::post('/contracts/{id}/activate', [LeaseContractController::class, 'activate'])->name('contracts.activate');
        Route::post('/contracts/{id}/remeasure', [LeaseContractController::class, 'remeasure'])->name('contracts.remeasure');
        Route::post('/contracts/{id}/cancel', [LeaseContractController::class, 'cancel'])->name('contracts.cancel');
        Route::get('/contracts/{id}/schedule', [LeaseContractController::class, 'schedule'])->name('contracts.schedule');
        Route::get('/contracts/{id}/versions', [LeaseContractController::class, 'versions'])->name('contracts.versions');
        Route::post('/contracts/{id}/post-periods', [LeasePostingController::class, 'postContract'])->name('contracts.post-periods');

        Route::post('/postings/run', [LeasePostingController::class, 'run'])->name('postings.run');

        Route::get('/reports/maturity', [LeaseReportController::class, 'maturity'])->name('reports.maturity');
    });
