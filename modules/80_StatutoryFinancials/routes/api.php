<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\StatutoryFinancials\Http\Controllers\DossierController;
use Modules\StatutoryFinancials\Http\Controllers\IndicatorController;
use Modules\StatutoryFinancials\Http\Controllers\LookupController;
use Modules\StatutoryFinancials\Http\Controllers\NotesController;
use Modules\StatutoryFinancials\Http\Controllers\PackageController;
use Modules\StatutoryFinancials\Http\Controllers\StatementController;

/*
|--------------------------------------------------------------------------
| Statutory financial statements (Mod 167 / Issue #413)
|--------------------------------------------------------------------------
| Italian civil-code financial statements with the XBRL deposit (Art. 2424-2425, 2435-bis, 2435-ter c.c., OIC 10) and the Swiss annual
| accounts (CO Art. 957-963b, Swiss GAAP FER): reclassification with rounding quadrature, cash flow statement, Nota Integrativa / Anhang,
| crisis indicators (DSCR) and the XBRL + PDF/A deposit package.
*/
Route::prefix('api/v1/statutory-financials')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:statutory_financials'])
    ->group(function (): void {
        Route::get('lookups', [LookupController::class, 'lookups'])->name('statutory-financials.lookups');

        Route::get('dossiers', [DossierController::class, 'index'])->name('statutory-financials.dossiers.index');
        Route::post('dossiers', [DossierController::class, 'store'])->name('statutory-financials.dossiers.store');
        Route::get('dossiers/{id}', [DossierController::class, 'show'])->whereUuid('id')->name('statutory-financials.dossiers.show');
        Route::put('dossiers/{id}', [DossierController::class, 'update'])->whereUuid('id')->name('statutory-financials.dossiers.update');
        Route::delete('dossiers/{id}', [DossierController::class, 'destroy'])->whereUuid('id')->name('statutory-financials.dossiers.destroy');
        Route::post('dossiers/{id}/transition', [DossierController::class, 'transition'])->whereUuid('id')->name('statutory-financials.dossiers.transition');
        Route::get('dossiers/{id}/eligibility', [DossierController::class, 'eligibility'])->whereUuid('id')->name('statutory-financials.dossiers.eligibility');

        Route::post('dossiers/{id}/generate', [StatementController::class, 'generate'])->whereUuid('id')->name('statutory-financials.statements.generate');
        Route::post('dossiers/{id}/cash-flow', [StatementController::class, 'cashFlow'])->whereUuid('id')->name('statutory-financials.statements.cash-flow');
        Route::get('dossiers/{id}/preview', [StatementController::class, 'preview'])->whereUuid('id')->name('statutory-financials.statements.preview');

        Route::get('dossiers/{id}/notes', [NotesController::class, 'show'])->whereUuid('id')->name('statutory-financials.notes.show');
        Route::post('dossiers/{id}/notes/populate', [NotesController::class, 'populate'])->whereUuid('id')->name('statutory-financials.notes.populate');
        Route::put('dossiers/{id}/notes', [NotesController::class, 'update'])->whereUuid('id')->name('statutory-financials.notes.update');

        Route::get('dossiers/{id}/indicators', [IndicatorController::class, 'show'])->whereUuid('id')->name('statutory-financials.indicators.show');
        Route::post('dossiers/{id}/indicators/simulate', [IndicatorController::class, 'simulate'])->whereUuid('id')->name('statutory-financials.indicators.simulate');
        Route::post('dossiers/{id}/indicators', [IndicatorController::class, 'compute'])->whereUuid('id')->name('statutory-financials.indicators.compute');

        Route::get('dossiers/{id}/package', [PackageController::class, 'show'])->whereUuid('id')->name('statutory-financials.package.show');
        Route::post('dossiers/{id}/xbrl/export', [PackageController::class, 'exportXbrl'])->whereUuid('id')->name('statutory-financials.package.xbrl');
        Route::post('dossiers/{id}/pdfa', [PackageController::class, 'generatePdfA'])->whereUuid('id')->name('statutory-financials.package.pdfa');
        Route::post('dossiers/{id}/artifacts/{artifactId}/verify', [PackageController::class, 'verify'])->whereUuid(['id', 'artifactId'])->name('statutory-financials.package.verify');
        Route::get('dossiers/{id}/artifacts/{artifactId}/download', [PackageController::class, 'download'])->whereUuid(['id', 'artifactId'])->name('statutory-financials.package.download');
    });
