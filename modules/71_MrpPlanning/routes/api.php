<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\MrpPlanning\Presentation\Http\Controllers\MrpController;

// mrp_planning: every member reads runs, proposals, pegging and the Gantt; production planners (and admins) run MRP, level
// the capacity and move Gantt blocks. mrp_release: firming, releasing and dismissing proposals creates purchase requisitions
// and production orders, so it is limited to the roles that commit materials and capacity.
Route::prefix('api/v1/mrp')->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])->group(function (): void {
    Route::middleware('module.access:mrp_planning')->group(function (): void {
        Route::get('runs', [MrpController::class, 'runs'])->name('mrp.runs.index');
        Route::post('runs', [MrpController::class, 'execute'])->name('mrp.runs.store');
        Route::get('runs/{id}', [MrpController::class, 'show'])->whereUuid('id')->name('mrp.runs.show');
        Route::post('runs/{id}/auto-level', [MrpController::class, 'autoLevel'])->whereUuid('id')->name('mrp.runs.auto-level');
        Route::get('runs/{id}/gantt', [MrpController::class, 'gantt'])->whereUuid('id')->name('mrp.runs.gantt');
        Route::get('proposals', [MrpController::class, 'proposals'])->name('mrp.proposals.index');
        Route::get('proposals/{id}/pegging', [MrpController::class, 'pegging'])->whereUuid('id')->name('mrp.proposals.pegging');
        Route::post('schedule-blocks/{id}/reschedule', [MrpController::class, 'reschedule'])->whereUuid('id')->name('mrp.schedule-blocks.reschedule');
    });

    Route::middleware('module.access:mrp_release')->group(function (): void {
        Route::post('proposals/actions', [MrpController::class, 'act'])->name('mrp.proposals.actions');
    });
});
