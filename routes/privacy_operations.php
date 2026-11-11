<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\PrivacyOperationsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Operational GDPR routes
|--------------------------------------------------------------------------
|
| Required from the privacy group of routes/api.php (/api/v1/settings/privacy, module access "system": owners and
| administrators), so the prefix, the name prefix and the middleware of the group apply.
|
*/

Route::get('/data-subject-requests/{id}', [PrivacyOperationsController::class, 'showRequest'])->whereUuid('id')->name('dsr.show');
Route::post('/data-subject-requests/{id}/verify-identity', [PrivacyOperationsController::class, 'verifyIdentity'])->whereUuid('id')->name('dsr.verify-identity');
Route::post('/data-subject-requests/{id}/start', [PrivacyOperationsController::class, 'start'])->whereUuid('id')->name('dsr.start');
Route::post('/data-subject-requests/{id}/complete', [PrivacyOperationsController::class, 'complete'])->whereUuid('id')->name('dsr.complete');
Route::post('/data-subject-requests/{id}/reject', [PrivacyOperationsController::class, 'reject'])->whereUuid('id')->name('dsr.reject');
Route::post('/data-subject-requests/{id}/extend', [PrivacyOperationsController::class, 'extend'])->whereUuid('id')->name('dsr.extend');
Route::get('/data-subject-requests/{id}/export', [PrivacyOperationsController::class, 'export'])->whereUuid('id')->name('dsr.export');

Route::get('/data-map', [PrivacyOperationsController::class, 'dataMap'])->name('data-map');

Route::get('/settings', [PrivacyOperationsController::class, 'showSettings'])->name('settings.show');
Route::put('/settings', [PrivacyOperationsController::class, 'updateSettings'])->name('settings.update');

Route::get('/retention-policies', [PrivacyOperationsController::class, 'retentionPolicies'])->name('retention-policies.index');
Route::put('/retention-policies/{entryKey}', [PrivacyOperationsController::class, 'savePolicy'])->name('retention-policies.save');
Route::delete('/retention-policies/{entryKey}', [PrivacyOperationsController::class, 'deletePolicy'])->name('retention-policies.destroy');
Route::get('/retention-runs', [PrivacyOperationsController::class, 'retentionRuns'])->name('retention-runs.index');
Route::post('/retention-runs', [PrivacyOperationsController::class, 'runRetention'])->name('retention-runs.store');

Route::get('/breaches/{id}', [PrivacyOperationsController::class, 'showBreach'])->whereUuid('id')->name('breaches.show');
Route::post('/breaches/{id}/contain', [PrivacyOperationsController::class, 'containBreach'])->whereUuid('id')->name('breaches.contain');
Route::post('/breaches/{id}/notify-authority', [PrivacyOperationsController::class, 'notifyAuthority'])->whereUuid('id')->name('breaches.notify-authority');
Route::post('/breaches/{id}/notification-not-required', [PrivacyOperationsController::class, 'notificationNotRequired'])->whereUuid('id')->name('breaches.notification-not-required');
Route::post('/breaches/{id}/notify-subjects', [PrivacyOperationsController::class, 'notifySubjects'])->whereUuid('id')->name('breaches.notify-subjects');
Route::post('/breaches/{id}/close', [PrivacyOperationsController::class, 'closeBreach'])->whereUuid('id')->name('breaches.close');
