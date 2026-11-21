<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\DataResidency\Presentation\Http\Controllers\DataResidencyController;

// sovereignty_policy: owners and administrators (DPO / CISO) read the posture and enforce the residency policy.
// sovereignty_byok: configure and rotate the customer-managed key. sovereignty_killswitch: the emergency suspension and the
// crypto-shredding (owners only, plus step-up MFA in the handler). sovereignty_attestation: issue and download certificates.
Route::prefix('api/v1/sovereignty')->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])->group(function (): void {
    Route::middleware('module.access:sovereignty_policy')->group(function (): void {
        Route::get('nodes', [DataResidencyController::class, 'nodes'])->name('sovereignty.nodes');
        Route::get('dashboard', [DataResidencyController::class, 'dashboard'])->name('sovereignty.dashboard');
        Route::get('policies', [DataResidencyController::class, 'policies'])->name('sovereignty.policies');
        Route::post('policies', [DataResidencyController::class, 'enforcePolicy'])->name('sovereignty.policies.enforce');
        Route::get('cross-border-rules', [DataResidencyController::class, 'crossBorderRules'])->name('sovereignty.rules');
        Route::post('cross-border-rules/validate', [DataResidencyController::class, 'validateTransfer'])->name('sovereignty.rules.validate');
    });

    Route::middleware('module.access:sovereignty_byok')->group(function (): void {
        Route::get('byok', [DataResidencyController::class, 'envelopes'])->name('sovereignty.byok');
        Route::get('byok/audit-logs', [DataResidencyController::class, 'auditLogs'])->name('sovereignty.byok.audit');
        Route::post('byok/configure', [DataResidencyController::class, 'configureByok'])->name('sovereignty.byok.configure');
        Route::post('byok/{id}/rotate', [DataResidencyController::class, 'rotate'])->whereUuid('id')->name('sovereignty.byok.rotate');
    });

    Route::post('byok/{id}/kill-switch', [DataResidencyController::class, 'killSwitch'])->middleware('module.access:sovereignty_killswitch')->whereUuid('id')->name('sovereignty.byok.kill-switch');

    Route::middleware('module.access:sovereignty_attestation')->group(function (): void {
        Route::post('attestations/generate', [DataResidencyController::class, 'generateAttestation'])->name('sovereignty.attestations.generate');
        Route::get('attestations/{id}', [DataResidencyController::class, 'attestation'])->whereUuid('id')->name('sovereignty.attestations.show');
        Route::get('attestations/{id}/pdf', [DataResidencyController::class, 'attestationPdf'])->whereUuid('id')->name('sovereignty.attestations.pdf');
    });
});
