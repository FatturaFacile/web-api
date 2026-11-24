<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Hse\Presentation\Http\Controllers\HseController;

// hse: members read the matrix, the clearance verdict and the physician judgments (outcome and prescriptions only); the HSE
// team (hr, hse, admins) writes. hse_medical: who records physician judgments. hse_clinical: only the occupational physician
// reads or writes clinical notes, which no other endpoint ever returns.
Route::prefix('api/v1/hse')->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])->group(function (): void {
    Route::middleware('module.access:hse')->group(function (): void {
        Route::get('compliance-matrix', [HseController::class, 'complianceMatrix'])->name('hse.compliance-matrix');
        Route::get('clearance', [HseController::class, 'clearance'])->name('hse.clearance');
        Route::get('expirations', [HseController::class, 'expirations'])->name('hse.expirations');
        Route::get('requirement-matrices', [HseController::class, 'matrices'])->name('hse.matrices.index');
        Route::post('requirement-matrices', [HseController::class, 'saveMatrix'])->name('hse.matrices.store');
        Route::put('requirement-matrices/{id}', [HseController::class, 'saveMatrix'])->whereUuid('id')->name('hse.matrices.update');
        Route::get('certificates', [HseController::class, 'certificates'])->name('hse.certificates.index');
        Route::post('certificates', [HseController::class, 'registerTraining'])->name('hse.certificates.store');
        Route::get('medical-visits', [HseController::class, 'medicalVisits'])->name('hse.medical-visits.index');
        Route::get('ppe-distributions', [HseController::class, 'ppeDistributions'])->name('hse.ppe.index');
        Route::post('ppe-distributions', [HseController::class, 'assignPpe'])->name('hse.ppe.store');
        Route::get('ppe-distributions/{id}/verbale', [HseController::class, 'verbale'])->whereUuid('id')->name('hse.ppe.verbale');
        Route::get('incidents', [HseController::class, 'incidents'])->name('hse.incidents.index');
        Route::post('incidents', [HseController::class, 'reportIncident'])->name('hse.incidents.store');
        Route::get('incidents/{id}/claim-draft', [HseController::class, 'claimDraft'])->whereUuid('id')->name('hse.incidents.claim-draft');
        Route::get('incidents/{id}/photos/{photoId}', [HseController::class, 'incidentPhoto'])->whereUuid(['id', 'photoId'])->name('hse.incidents.photo');
        Route::post('toolbox-talks', [HseController::class, 'toolBoxTalk'])->name('hse.toolbox-talks.store');
        Route::get('audits', [HseController::class, 'audits'])->name('hse.audits.index');
        Route::post('audits/mssl', [HseController::class, 'saveAudit'])->name('hse.audits.mssl');
        Route::post('audits/dvr', [HseController::class, 'registerDvr'])->name('hse.audits.dvr');
    });

    Route::middleware('module.access:hse_medical')->group(function (): void {
        Route::post('medical-visits', [HseController::class, 'recordMedical'])->name('hse.medical-visits.store');
    });

    Route::middleware('module.access:hse_clinical')->group(function (): void {
        Route::get('medical-visits/{id}/clinical-notes', [HseController::class, 'clinicalNotes'])->whereUuid('id')->name('hse.medical-visits.clinical-notes');
    });
});
