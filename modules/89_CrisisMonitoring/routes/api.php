<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CrisisMonitoring\Http\Controllers\DashboardController;
use Modules\CrisisMonitoring\Http\Controllers\DossierController;
use Modules\CrisisMonitoring\Http\Controllers\ProfileController;
use Modules\CrisisMonitoring\Http\Controllers\StressTestController;
use Modules\CrisisMonitoring\Http\Controllers\SubordinatedLoanController;

// crisis_monitoring: the finance roles read the dashboard, the timeline, the stress test, the dossier and the registry; the accountants and
// the administrators change the profile and the registry and store snapshots. The stress test is a POST because of its body, but saves nothing.
Route::prefix('api/v1/crisis-monitoring')->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])->group(function (): void {
    Route::middleware('module.access:crisis_monitoring,read')->group(function (): void {
        Route::get('dashboard', [DashboardController::class, 'dashboard'])->name('crisis-monitoring.dashboard');
        Route::get('timeline', [DashboardController::class, 'timeline'])->name('crisis-monitoring.timeline');
        Route::get('cnc-practical-test', [DashboardController::class, 'cnc'])->name('crisis-monitoring.cnc');
        Route::get('rangruecktritt/required', [DashboardController::class, 'required'])->name('crisis-monitoring.rangruecktritt.required');
        Route::get('metadata', [DashboardController::class, 'metadata'])->name('crisis-monitoring.metadata');
        Route::get('profile', [ProfileController::class, 'show'])->name('crisis-monitoring.profile.show');
        Route::post('stress-test', [StressTestController::class, 'run'])->name('crisis-monitoring.stress-test');
        Route::get('dossier/pdf', [DossierController::class, 'pdf'])->name('crisis-monitoring.dossier.pdf');
        Route::get('subordinated-loans', [SubordinatedLoanController::class, 'index'])->name('crisis-monitoring.subordinated-loans.index');
        Route::get('subordinated-loans/{id}/agreement/pdf', [SubordinatedLoanController::class, 'agreementPdf'])->whereUuid('id')->name('crisis-monitoring.subordinated-loans.agreement');
    });

    Route::middleware('module.access:crisis_monitoring,write')->group(function (): void {
        Route::post('compute', [DashboardController::class, 'compute'])->name('crisis-monitoring.compute');
        Route::put('profile', [ProfileController::class, 'update'])->name('crisis-monitoring.profile.update');
        Route::post('subordinated-loans', [SubordinatedLoanController::class, 'store'])->name('crisis-monitoring.subordinated-loans.store');
        Route::put('subordinated-loans/{id}', [SubordinatedLoanController::class, 'update'])->whereUuid('id')->name('crisis-monitoring.subordinated-loans.update');
        Route::delete('subordinated-loans/{id}', [SubordinatedLoanController::class, 'destroy'])->whereUuid('id')->name('crisis-monitoring.subordinated-loans.destroy');
    });
});
