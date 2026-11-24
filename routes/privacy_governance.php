<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Privacy\BreachIncidentController;
use App\Http\Controllers\Api\V1\Privacy\ConsentLedgerController;
use App\Http\Controllers\Api\V1\Privacy\DsarController;
use App\Http\Controllers\Api\V1\Privacy\RetentionRuleController;
use App\Http\Controllers\Api\V1\Privacy\RopaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Privacy & Data Governance Suite (module 096): /api/v1/privacy
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated tenant group, behind module access "system" (owners and
| administrators: the data protection officer role). RoPA (GDPR art. 30, nLPD art. 12), DSAR (art. 15/17/20, nLPD
| art. 25/28), consent ledger, 72 hour breach assistant (art. 33, nLPD art. 24) and retention rules.
|
*/

Route::get('/ropa', [RopaController::class, 'index'])->name('ropa.index');
Route::post('/ropa', [RopaController::class, 'store'])->name('ropa.store');
Route::post('/ropa/seed-defaults', [RopaController::class, 'seedDefaults'])->name('ropa.seed-defaults');
Route::get('/ropa/export', [RopaController::class, 'export'])->middleware('privacy.sensitive.log:gdpr_exports')->name('ropa.export');
Route::put('/ropa/{id}', [RopaController::class, 'update'])->whereUuid('id')->name('ropa.update');
Route::delete('/ropa/{id}', [RopaController::class, 'destroy'])->whereUuid('id')->name('ropa.destroy');

Route::get('/dsar', [DsarController::class, 'index'])->name('dsar.index');
Route::post('/dsar', [DsarController::class, 'store'])->name('dsar.store');
Route::get('/dsar/{id}', [DsarController::class, 'show'])->whereUuid('id')->name('dsar.show');
Route::post('/dsar/{id}/verify-identity', [DsarController::class, 'verifyIdentity'])->whereUuid('id')->name('dsar.verify-identity');
Route::post('/dsar/{id}/extend', [DsarController::class, 'extend'])->whereUuid('id')->name('dsar.extend');
Route::post('/dsar/{id}/generate-package', [DsarController::class, 'generatePackage'])->whereUuid('id')->middleware('privacy.sensitive.log:gdpr_exports')->name('dsar.generate-package');
Route::get('/dsar/{id}/download-package', [DsarController::class, 'downloadPackage'])->whereUuid('id')->middleware('privacy.sensitive.log:gdpr_exports')->name('dsar.download-package');
Route::post('/dsar/{id}/execute-erasure', [DsarController::class, 'executeErasure'])->whereUuid('id')->middleware('privacy.sensitive.log:gdpr_exports')->name('dsar.execute-erasure');

Route::get('/consents', [ConsentLedgerController::class, 'index'])->name('consents.index');
Route::post('/consents', [ConsentLedgerController::class, 'store'])->name('consents.store');

Route::get('/breaches', [BreachIncidentController::class, 'index'])->name('breaches.index');
Route::get('/breaches/active-countdown', [BreachIncidentController::class, 'activeCountdown'])->name('breaches.active-countdown');
Route::post('/breaches', [BreachIncidentController::class, 'store'])->name('breaches.store');
Route::put('/breaches/{id}', [BreachIncidentController::class, 'update'])->whereUuid('id')->name('breaches.update');
Route::get('/breaches/{id}/notification-dossier', [BreachIncidentController::class, 'dossier'])->whereUuid('id')->name('breaches.notification-dossier');

Route::get('/retention-rules', [RetentionRuleController::class, 'index'])->name('retention-rules.index');
Route::put('/retention-rules/{id}', [RetentionRuleController::class, 'update'])->whereUuid('id')->name('retention-rules.update');
Route::post('/retention-rules/run-now', [RetentionRuleController::class, 'runNow'])->name('retention-rules.run-now');
Route::get('/retention-rules/runs', [RetentionRuleController::class, 'runs'])->name('retention-rules.runs');
