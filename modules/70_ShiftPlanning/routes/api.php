<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ShiftPlanning\Presentation\Http\Controllers\EmployeeShiftController;
use Modules\ShiftPlanning\Presentation\Http\Controllers\RosterController;

// shift_planning: HR, production and managers build, publish and lock rosters and decide swaps (labour cost is visible
// here). shift_planning_self: every employee reads the own agenda, claims open shifts and swaps with colleagues.
Route::prefix('api/v1/shift-planning')->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])->group(function (): void {
    Route::middleware('module.access:shift_planning')->group(function (): void {
        Route::get('templates', [RosterController::class, 'templates'])->name('shift-planning.templates.index');
        Route::post('templates', [RosterController::class, 'storeTemplate'])->name('shift-planning.templates.store');
        Route::put('templates/{id}', [RosterController::class, 'updateTemplate'])->whereUuid('id')->name('shift-planning.templates.update');
        Route::get('periods', [RosterController::class, 'periods'])->name('shift-planning.periods.index');
        Route::post('periods/generate', [RosterController::class, 'generate'])->name('shift-planning.periods.generate');
        Route::get('periods/{id}/matrix', [RosterController::class, 'matrix'])->whereUuid('id')->name('shift-planning.periods.matrix');
        Route::post('periods/{id}/publish', [RosterController::class, 'publish'])->whereUuid('id')->name('shift-planning.periods.publish');
        Route::post('periods/{id}/lock', [RosterController::class, 'lock'])->whereUuid('id')->name('shift-planning.periods.lock');
        Route::post('assignments', [RosterController::class, 'createAssignment'])->name('shift-planning.assignments.store');
        Route::patch('assignments/{id}', [RosterController::class, 'moveAssignment'])->whereUuid('id')->name('shift-planning.assignments.move');
        Route::get('swaps', [RosterController::class, 'swaps'])->name('shift-planning.swaps.index');
        Route::post('swaps/{id}/decision', [RosterController::class, 'decideSwap'])->whereUuid('id')->name('shift-planning.swaps.decision');
    });

    Route::prefix('my')->middleware('module.access:shift_planning_self')->group(function (): void {
        Route::get('shifts', [EmployeeShiftController::class, 'myShifts'])->name('shift-planning.my.shifts');
        Route::get('open-shifts', [EmployeeShiftController::class, 'openShifts'])->name('shift-planning.my.open-shifts');
        Route::post('open-shifts/{id}/claim', [EmployeeShiftController::class, 'claim'])->whereUuid('id')->name('shift-planning.my.claim');
        Route::get('swaps', [EmployeeShiftController::class, 'mySwaps'])->name('shift-planning.my.swaps');
        Route::post('swaps', [EmployeeShiftController::class, 'requestSwap'])->name('shift-planning.my.swaps.store');
        Route::post('swaps/{id}/respond', [EmployeeShiftController::class, 'respond'])->whereUuid('id')->name('shift-planning.my.swaps.respond');
    });
});
