<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\GreenCare\Http\Controllers\ContractController;
use Modules\GreenCare\Http\Controllers\CrewController;
use Modules\GreenCare\Http\Controllers\DashboardController;
use Modules\GreenCare\Http\Controllers\LicenseController;
use Modules\GreenCare\Http\Controllers\RouteController;
use Modules\GreenCare\Http\Controllers\SiteController;
use Modules\GreenCare\Http\Controllers\StopController;
use Modules\GreenCare\Http\Controllers\TreatmentController;
use Modules\GreenCare\Http\Controllers\WasteSlipController;

/*
|--------------------------------------------------------------------------
| Green care: gardening and green maintenance vertical (Mod 126 / Issue #372)
|--------------------------------------------------------------------------
*/
Route::prefix('api/v1/green-care')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:green_care'])
    ->group(function (): void {
        Route::get('dashboard', [DashboardController::class, 'dashboard'])->name('green-care.dashboard');
        Route::get('reference', [DashboardController::class, 'reference'])->name('green-care.reference');

        Route::get('sites', [SiteController::class, 'index'])->name('green-care.sites.index');
        Route::post('sites', [SiteController::class, 'store'])->name('green-care.sites.store');
        Route::get('sites/{id}', [SiteController::class, 'show'])->whereUuid('id')->name('green-care.sites.show');
        Route::put('sites/{id}', [SiteController::class, 'update'])->whereUuid('id')->name('green-care.sites.update');
        Route::delete('sites/{id}', [SiteController::class, 'destroy'])->whereUuid('id')->name('green-care.sites.destroy');

        Route::get('contracts', [ContractController::class, 'index'])->name('green-care.contracts.index');
        Route::post('contracts', [ContractController::class, 'store'])->name('green-care.contracts.store');
        Route::get('contracts/{id}', [ContractController::class, 'show'])->whereUuid('id')->name('green-care.contracts.show');
        Route::put('contracts/{id}', [ContractController::class, 'update'])->whereUuid('id')->name('green-care.contracts.update');
        Route::delete('contracts/{id}', [ContractController::class, 'destroy'])->whereUuid('id')->name('green-care.contracts.destroy');
        Route::post('contracts/{id}/activate', [ContractController::class, 'activate'])->whereUuid('id')->name('green-care.contracts.activate');
        Route::post('contracts/{id}/suspend', [ContractController::class, 'suspend'])->whereUuid('id')->name('green-care.contracts.suspend');
        Route::post('contracts/{id}/billing-preview', [ContractController::class, 'billingPreview'])->whereUuid('id')->name('green-care.contracts.billing-preview');
        Route::post('contracts/{id}/generate-invoice', [ContractController::class, 'generateInvoice'])->whereUuid('id')->name('green-care.contracts.generate-invoice');

        Route::get('crews', [CrewController::class, 'index'])->name('green-care.crews.index');
        Route::post('crews', [CrewController::class, 'store'])->name('green-care.crews.store');
        Route::get('crews/{id}', [CrewController::class, 'show'])->whereUuid('id')->name('green-care.crews.show');
        Route::put('crews/{id}', [CrewController::class, 'update'])->whereUuid('id')->name('green-care.crews.update');
        Route::delete('crews/{id}', [CrewController::class, 'destroy'])->whereUuid('id')->name('green-care.crews.destroy');

        Route::get('licenses', [LicenseController::class, 'index'])->name('green-care.licenses.index');
        Route::post('licenses', [LicenseController::class, 'store'])->name('green-care.licenses.store');
        Route::put('licenses/{id}', [LicenseController::class, 'update'])->whereUuid('id')->name('green-care.licenses.update');
        Route::delete('licenses/{id}', [LicenseController::class, 'destroy'])->whereUuid('id')->name('green-care.licenses.destroy');

        Route::get('routes', [RouteController::class, 'index'])->name('green-care.routes.index');
        Route::post('routes', [RouteController::class, 'store'])->name('green-care.routes.store');
        Route::post('routes/optimize', [RouteController::class, 'optimize'])->name('green-care.routes.optimize');
        Route::post('routes/auto-reschedule-weather', [RouteController::class, 'autoRescheduleWeather'])->name('green-care.routes.auto-reschedule-weather');
        Route::get('routes/{id}', [RouteController::class, 'show'])->whereUuid('id')->name('green-care.routes.show');
        Route::put('routes/{id}', [RouteController::class, 'update'])->whereUuid('id')->name('green-care.routes.update');
        Route::delete('routes/{id}', [RouteController::class, 'destroy'])->whereUuid('id')->name('green-care.routes.destroy');
        Route::post('routes/{id}/refresh-weather', [RouteController::class, 'refreshWeather'])->whereUuid('id')->name('green-care.routes.refresh-weather');

        Route::post('route-stops/{id}/intervention', [StopController::class, 'intervention'])->whereUuid('id')->name('green-care.stops.intervention');
        Route::post('route-stops/{id}/photos', [StopController::class, 'uploadPhotos'])->whereUuid('id')->name('green-care.stops.photos.upload');
        Route::get('route-stops/{id}/photos/{type}/{index}', [StopController::class, 'downloadPhoto'])->whereUuid('id')->whereIn('type', ['before', 'after'])->whereNumber('index')->name('green-care.stops.photos.download');

        Route::get('treatments', [TreatmentController::class, 'index'])->name('green-care.treatments.index');
        Route::post('treatments', [TreatmentController::class, 'store'])->name('green-care.treatments.store');
        Route::post('treatments/validate-license', [TreatmentController::class, 'validateLicense'])->name('green-care.treatments.validate-license');
        Route::get('treatments/export-pdf', [TreatmentController::class, 'exportPdf'])->name('green-care.treatments.export-pdf');
        Route::get('treatments/{id}', [TreatmentController::class, 'show'])->whereUuid('id')->name('green-care.treatments.show');

        Route::get('slips', [WasteSlipController::class, 'index'])->name('green-care.slips.index');
        Route::post('slips', [WasteSlipController::class, 'store'])->name('green-care.slips.store');
        Route::get('slips/summary', [WasteSlipController::class, 'summary'])->name('green-care.slips.summary');
        Route::get('slips/{id}', [WasteSlipController::class, 'show'])->whereUuid('id')->name('green-care.slips.show');
        Route::put('slips/{id}', [WasteSlipController::class, 'update'])->whereUuid('id')->name('green-care.slips.update');
        Route::delete('slips/{id}', [WasteSlipController::class, 'destroy'])->whereUuid('id')->name('green-care.slips.destroy');
    });
