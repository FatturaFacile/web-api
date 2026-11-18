<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CorporateGroup\Http\Controllers\CompanyController;
use Modules\CorporateGroup\Http\Controllers\ConsolidationController;
use Modules\CorporateGroup\Http\Controllers\IntercompanyController;

Route::prefix('api/v1/corporate-group')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'company.context'])
    ->name('corporate-group.')
    ->group(function (): void {
        Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
        Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
        Route::get('companies/{id}', [CompanyController::class, 'show'])->name('companies.show');
        Route::put('companies/{id}', [CompanyController::class, 'update'])->name('companies.update');
        Route::delete('companies/{id}', [CompanyController::class, 'destroy'])->name('companies.destroy');

        Route::get('companies/{id}/sites', [CompanyController::class, 'sites'])->name('sites.index');
        Route::post('companies/{id}/sites', [CompanyController::class, 'storeSite'])->name('sites.store');
        Route::delete('companies/{id}/sites/{siteId}', [CompanyController::class, 'destroySite'])->name('sites.destroy');

        Route::get('companies/{id}/members', [CompanyController::class, 'members'])->name('members.index');
        Route::post('companies/{id}/members', [CompanyController::class, 'storeMember'])->name('members.store');
        Route::delete('companies/{id}/members/{userId}', [CompanyController::class, 'destroyMember'])->name('members.destroy');

        Route::get('companies/{id}/accounting-settings', [CompanyController::class, 'accountingSettings'])->name('settings.show');
        Route::put('companies/{id}/accounting-settings', [CompanyController::class, 'updateAccountingSettings'])->name('settings.update');

        Route::get('intercompany/transactions', [IntercompanyController::class, 'index'])->name('intercompany.index');
        Route::post('intercompany/transactions', [IntercompanyController::class, 'store'])->name('intercompany.store');
        Route::get('intercompany/transactions/{id}', [IntercompanyController::class, 'show'])->name('intercompany.show');
        Route::post('intercompany/transactions/{id}/cancel', [IntercompanyController::class, 'cancel'])->name('intercompany.cancel');

        Route::get('consolidation/runs', [ConsolidationController::class, 'index'])->name('consolidation.index');
        Route::post('consolidation/runs', [ConsolidationController::class, 'store'])->name('consolidation.store');
        Route::get('consolidation/runs/{id}', [ConsolidationController::class, 'show'])->name('consolidation.show');
    });
