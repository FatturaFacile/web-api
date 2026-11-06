<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\HumanResources\Http\Controllers\ShiftPlanningController;

Route::prefix('api/v1/hr')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:hr'])
    ->name('api.v1.hr.')
    ->where(['id' => '[0-9a-fA-F-]{36}'])
    ->group(function (): void {
        // Shift planning
        Route::get('/shift-types', [ShiftPlanningController::class, 'shiftTypes'])->name('shift-types.index');
        Route::post('/shift-types', [ShiftPlanningController::class, 'storeShiftType'])->name('shift-types.store');
        Route::put('/shift-types/{id}', [ShiftPlanningController::class, 'updateShiftType'])->name('shift-types.update');
        Route::get('/shift-sequences', [ShiftPlanningController::class, 'sequences'])->name('shift-sequences.index');
        Route::post('/shift-sequences', [ShiftPlanningController::class, 'storeSequence'])->name('shift-sequences.store');
        Route::post('/shift-sequences/assignments', [ShiftPlanningController::class, 'assignSequence'])->name('shift-sequences.assign');
        Route::post('/shift-exceptions', [ShiftPlanningController::class, 'storeException'])->name('shift-exceptions.store');
        Route::post('/staffing-needs', [ShiftPlanningController::class, 'storeStaffingNeed'])->name('staffing-needs.store');
        Route::get('/shifts/board', [ShiftPlanningController::class, 'board'])->name('shifts.board');
        Route::post('/shifts/generate', [ShiftPlanningController::class, 'generate'])->name('shifts.generate');
        Route::post('/shifts/publish', [ShiftPlanningController::class, 'publish'])->name('shifts.publish');
        Route::post('/shifts', [ShiftPlanningController::class, 'storeShift'])->name('shifts.store');
        Route::put('/shifts/{id}', [ShiftPlanningController::class, 'updateShift'])->name('shifts.update');
        Route::delete('/shifts/{id}', [ShiftPlanningController::class, 'destroyShift'])->name('shifts.destroy');
        Route::get('/absence-plan', [ShiftPlanningController::class, 'absencePlan'])->name('absence-plan');
    });

// Employee self-service: no module role, each answer is limited to the authenticated user.
Route::prefix('api/v1/hr/time')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])
    ->name('api.v1.hr.time.')
    ->group(function (): void {
        Route::get('/my-shifts', [ShiftPlanningController::class, 'myShifts'])->name('my-shifts');
    });
