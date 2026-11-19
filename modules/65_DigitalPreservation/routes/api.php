<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\DigitalPreservation\Presentation\Http\Controllers\PreservationAuditLogController;
use Modules\DigitalPreservation\Presentation\Http\Controllers\PreservationBatchController;
use Modules\DigitalPreservation\Presentation\Http\Controllers\PreservationConfigurationController;
use Modules\DigitalPreservation\Presentation\Http\Controllers\PreservationDashboardController;
use Modules\DigitalPreservation\Presentation\Http\Controllers\PreservationItemController;

Route::prefix('api/v1/preservation')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:digital_preservation'])
    ->group(function (): void {
        Route::get('dashboard', PreservationDashboardController::class)->name('preservation.dashboard');

        Route::get('configuration', [PreservationConfigurationController::class, 'show'])->name('preservation.configuration.show');
        Route::put('configuration', [PreservationConfigurationController::class, 'update'])->name('preservation.configuration.update');
        Route::post('configuration/test-connection', [PreservationConfigurationController::class, 'testConnection'])->name('preservation.configuration.test');

        Route::get('batches', [PreservationBatchController::class, 'index'])->name('preservation.batches.index');
        Route::post('batches', [PreservationBatchController::class, 'store'])->name('preservation.batches.store');
        Route::get('batches/{id}', [PreservationBatchController::class, 'show'])->whereUuid('id')->name('preservation.batches.show');
        Route::post('batches/{id}/seal', [PreservationBatchController::class, 'seal'])->whereUuid('id')->name('preservation.batches.seal');
        Route::post('batches/{id}/dispatch', [PreservationBatchController::class, 'dispatch'])->whereUuid('id')->name('preservation.batches.dispatch');
        Route::post('batches/{id}/verify', [PreservationBatchController::class, 'verify'])->whereUuid('id')->name('preservation.batches.verify');
        Route::get('batches/{id}/export-pdd', [PreservationBatchController::class, 'exportPdd'])->whereUuid('id')->name('preservation.batches.export');

        Route::get('items', [PreservationItemController::class, 'index'])->name('preservation.items.index');
        Route::post('items/{id}/verify', [PreservationItemController::class, 'verify'])->whereUuid('id')->name('preservation.items.verify');
        Route::get('items/{id}/export-pdd', [PreservationItemController::class, 'exportPdd'])->whereUuid('id')->name('preservation.items.export');

        Route::get('audit-logs', [PreservationAuditLogController::class, 'index'])->name('preservation.audit-logs.index');
    });
