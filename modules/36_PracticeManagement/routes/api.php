<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\PracticeManagement\Http\Controllers\PracticeController;

Route::prefix('api/v1/practice')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:practice'])
    ->name('api.v1.practice.')
    ->where(['id' => '[0-9a-fA-F-]{36}'])
    ->group(function (): void {
        Route::get('/activity-control', [PracticeController::class, 'activityControl'])->name('activity-control');
        Route::post('/fee-notes/from-activities', [PracticeController::class, 'feeNoteFromActivities'])->name('fee-notes.from-activities');
        Route::get('/fee-notes/{id}/activities', [PracticeController::class, 'feeNoteActivities'])->name('fee-notes.activities');
        Route::get('/closing-overview', [PracticeController::class, 'closingOverview'])->name('closing-overview');
        Route::post('/closings', [PracticeController::class, 'openClosing'])->name('closings.store');
        Route::put('/closings/{id}/checklist', [PracticeController::class, 'checklist'])->name('closings.checklist');
        Route::post('/closings/{id}/close', [PracticeController::class, 'close'])->name('closings.close');
        Route::post('/closings/{id}/reopen', [PracticeController::class, 'reopen'])->name('closings.reopen');
    });
