<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\OcrExtraction\Presentation\Http\Controllers\InboundEmailWebhookController;
use Modules\OcrExtraction\Presentation\Http\Controllers\OcrCaptureController;
use Modules\OcrExtraction\Presentation\Http\Controllers\OcrConfigurationController;
use Modules\OcrExtraction\Presentation\Http\Controllers\OcrInboxController;
use Modules\OcrExtraction\Presentation\Http\Controllers\OcrSourceController;
use Modules\OcrExtraction\Presentation\Http\Middleware\VerifyInboundEmailSignature;

// ocr_inbox: the finance team reads and reviews the AI Inbox. ocr_capture: every member may send a photo from the app.
// ocr_settings: owners and admins choose the OCR engine (see config/permissions.php).
Route::prefix('api/v1')->group(function (): void {
    Route::post('webhooks/inbound-email', InboundEmailWebhookController::class)
        ->middleware(['api', 'throttle:120,1', VerifyInboundEmailSignature::class])
        ->name('ocr-inbox.inbound-email');

    // Authenticated by the signature of the URL (signed, expiring): the viewer cannot send a bearer token with a PDF.js fetch.
    Route::get('ocr-inbox-downloads/{job}', OcrSourceController::class)
        ->middleware(['api', 'signed'])
        ->whereUuid('job')
        ->name('ocr-inbox.source');

    Route::prefix('ocr-inbox')->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])->group(function (): void {
        Route::post('capture', [OcrCaptureController::class, 'capture'])
            ->middleware(['module.access:ocr_capture', 'throttle:60,1'])
            ->name('ocr-inbox.capture');

        Route::middleware('module.access:ocr_settings')->group(function (): void {
            Route::get('configuration', [OcrConfigurationController::class, 'show'])->name('ocr-inbox.configuration.show');
            Route::put('configuration', [OcrConfigurationController::class, 'update'])->name('ocr-inbox.configuration.update');
        });

        Route::middleware('module.access:ocr_inbox')->group(function (): void {
            Route::get('/', [OcrInboxController::class, 'index'])->name('ocr-inbox.index');
            Route::post('documents', [OcrInboxController::class, 'enqueue'])->name('ocr-inbox.enqueue');
            Route::get('{id}', [OcrInboxController::class, 'show'])->whereUuid('id')->name('ocr-inbox.show');
            Route::put('{id}/draft', [OcrInboxController::class, 'saveDraft'])->whereUuid('id')->name('ocr-inbox.draft');
            Route::post('{id}/confirm', [OcrInboxController::class, 'confirm'])->whereUuid('id')->name('ocr-inbox.confirm');
            Route::post('{id}/reject', [OcrInboxController::class, 'reject'])->whereUuid('id')->name('ocr-inbox.reject');
            Route::post('{id}/rerun', [OcrInboxController::class, 'rerun'])->whereUuid('id')->name('ocr-inbox.rerun');
        });
    });
});
