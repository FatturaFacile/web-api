<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Documents\Http\Controllers\AiSettingController;
use Modules\Documents\Http\Controllers\DocumentController;
use Modules\Documents\Http\Controllers\DocumentExtractionController;
use Modules\Documents\Http\Controllers\DocumentFolderController;
use Modules\Documents\Http\Controllers\DocumentLifecycleController;
use Modules\Documents\Http\Controllers\DocumentTagController;
use Modules\Documents\Http\Controllers\DocumentVersionController;
use Modules\Documents\Http\Controllers\RetentionPolicyController;

Route::prefix('api/v1/dms')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:documents'])
    ->group(function (): void {
        Route::get('folders', [DocumentFolderController::class, 'index'])->name('dms.folders.index');
        Route::post('folders', [DocumentFolderController::class, 'store'])->name('dms.folders.store');
        Route::put('folders/{id}', [DocumentFolderController::class, 'update'])->whereUuid('id')->name('dms.folders.update');
        Route::delete('folders/{id}', [DocumentFolderController::class, 'destroy'])->whereUuid('id')->name('dms.folders.destroy');

        Route::get('tags', [DocumentTagController::class, 'index'])->name('dms.tags.index');
        Route::post('tags', [DocumentTagController::class, 'store'])->name('dms.tags.store');
        Route::delete('tags/{id}', [DocumentTagController::class, 'destroy'])->whereUuid('id')->name('dms.tags.destroy');

        Route::get('retention-policies', [RetentionPolicyController::class, 'index'])->name('dms.retention.index');
        Route::post('retention-policies', [RetentionPolicyController::class, 'store'])->name('dms.retention.store');
        Route::put('retention-policies/{id}', [RetentionPolicyController::class, 'update'])->whereUuid('id')->name('dms.retention.update');
        Route::delete('retention-policies/{id}', [RetentionPolicyController::class, 'destroy'])->whereUuid('id')->name('dms.retention.destroy');

        Route::get('ai-settings', [AiSettingController::class, 'show'])->name('dms.ai-settings.show');
        Route::put('ai-settings', [AiSettingController::class, 'update'])->name('dms.ai-settings.update');

        Route::get('documents', [DocumentController::class, 'index'])->name('dms.documents.index');
        Route::post('documents', [DocumentController::class, 'store'])->name('dms.documents.store');
        Route::get('documents/duplicates', [DocumentController::class, 'duplicates'])->name('dms.documents.duplicates');
        Route::post('documents/import-attachment', [DocumentController::class, 'importAttachment'])->name('dms.documents.import-attachment');
        Route::get('documents/{id}', [DocumentController::class, 'show'])->whereUuid('id')->name('dms.documents.show');
        Route::put('documents/{id}', [DocumentController::class, 'update'])->whereUuid('id')->name('dms.documents.update');
        Route::delete('documents/{id}', [DocumentController::class, 'destroy'])->whereUuid('id')->name('dms.documents.destroy');
        Route::get('documents/{id}/links', [DocumentController::class, 'linkedRecords'])->whereUuid('id')->name('dms.documents.links.index');
        Route::post('documents/{id}/links', [DocumentController::class, 'link'])->whereUuid('id')->name('dms.documents.link');
        Route::delete('documents/{id}/links/{linkId}', [DocumentController::class, 'unlink'])->whereUuid(['id', 'linkId'])->name('dms.documents.unlink');
        Route::post('documents/{id}/legal-hold', [DocumentController::class, 'placeLegalHold'])->whereUuid('id')->name('dms.documents.legal-hold.place');
        Route::delete('documents/{id}/legal-hold', [DocumentController::class, 'releaseLegalHold'])->whereUuid('id')->name('dms.documents.legal-hold.release');

        Route::post('documents/{id}/check-out', [DocumentLifecycleController::class, 'checkOut'])->whereUuid('id')->name('dms.documents.check-out');
        Route::delete('documents/{id}/check-out', [DocumentLifecycleController::class, 'cancelCheckOut'])->whereUuid('id')->name('dms.documents.check-out.cancel');
        Route::post('documents/{id}/check-in', [DocumentLifecycleController::class, 'checkIn'])->whereUuid('id')->name('dms.documents.check-in');
        Route::get('documents/{id}/audit-trail', [DocumentLifecycleController::class, 'auditTrail'])->whereUuid('id')->name('dms.documents.audit-trail');

        Route::get('documents/{id}/versions', [DocumentVersionController::class, 'index'])->whereUuid('id')->name('dms.versions.index');
        Route::post('documents/{id}/versions', [DocumentVersionController::class, 'store'])->whereUuid('id')->name('dms.versions.store');
        Route::get('documents/{id}/versions/{versionId}/download', [DocumentVersionController::class, 'download'])->whereUuid(['id', 'versionId'])->name('dms.versions.download');
        Route::get('documents/{id}/versions/{versionId}/verify', [DocumentVersionController::class, 'verify'])->whereUuid(['id', 'versionId'])->name('dms.versions.verify');

        Route::post('documents/{id}/extractions', [DocumentExtractionController::class, 'store'])->whereUuid('id')->name('dms.extractions.store');
        Route::get('documents/{id}/extractions', [DocumentExtractionController::class, 'index'])->whereUuid('id')->name('dms.extractions.index');
        Route::post('extractions/{id}/confirm', [DocumentExtractionController::class, 'confirm'])->whereUuid('id')->name('dms.extractions.confirm');
        Route::post('extractions/{id}/reject', [DocumentExtractionController::class, 'reject'])->whereUuid('id')->name('dms.extractions.reject');
    });
