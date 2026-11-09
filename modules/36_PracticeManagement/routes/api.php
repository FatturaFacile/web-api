<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\PracticeManagement\Http\Controllers\PracticeController;
use Modules\PracticeManagement\Http\Controllers\RetainerController;

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

        Route::get('/retainers', [RetainerController::class, 'index'])->name('retainers.index');
        Route::get('/retainers/balances', [RetainerController::class, 'balances'])->name('retainers.balances');
        Route::post('/retainers', [RetainerController::class, 'store'])->name('retainers.store');
        Route::post('/retainers/{id}/apply', [RetainerController::class, 'apply'])->name('retainers.apply');
        Route::post('/retainers/{id}/void', [RetainerController::class, 'void'])->name('retainers.void');
        Route::get('/fee-notes/{id}/retainers', [RetainerController::class, 'feeNote'])->name('fee-notes.retainers');
    });
