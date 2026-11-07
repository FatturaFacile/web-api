<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\HumanResources\Http\Controllers\RecruitingController;
use Modules\HumanResources\Http\Controllers\ShiftPlanningController;
use Modules\HumanResources\Http\Controllers\TimeClockDetailsController;

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

        // Schedule groups and badges
        Route::get('/schedule-groups', [TimeClockDetailsController::class, 'groups'])->name('schedule-groups.index');
        Route::post('/schedule-groups', [TimeClockDetailsController::class, 'storeGroup'])->name('schedule-groups.store');
        Route::post('/schedule-groups/{id}/rules', [TimeClockDetailsController::class, 'storeRule'])->name('schedule-groups.rules.store');
        Route::post('/schedule-groups/{id}/members', [TimeClockDetailsController::class, 'storeMember'])->name('schedule-groups.members.store');
        Route::post('/time-badges', [TimeClockDetailsController::class, 'storeBadge'])->name('time-badges.store');

        // Recruiting
        Route::get('/job-postings', [RecruitingController::class, 'postings'])->name('job-postings.index');
        Route::post('/job-postings', [RecruitingController::class, 'storePosting'])->name('job-postings.store');
        Route::post('/job-postings/{id}/close', [RecruitingController::class, 'closePosting'])->name('job-postings.close');
        Route::get('/pipeline-stages', [RecruitingController::class, 'stages'])->name('pipeline-stages.index');
        Route::get('/job-applications', [RecruitingController::class, 'applications'])->name('job-applications.index');
        Route::post('/job-applications', [RecruitingController::class, 'storeApplication'])->name('job-applications.store');
        Route::get('/job-applications/{id}', [RecruitingController::class, 'showApplication'])->name('job-applications.show');
        Route::delete('/job-applications/{id}', [RecruitingController::class, 'erase'])->name('job-applications.erase');
        Route::post('/job-applications/{id}/move', [RecruitingController::class, 'moveApplication'])->name('job-applications.move');
        Route::post('/job-applications/{id}/notes', [RecruitingController::class, 'storeNote'])->name('job-applications.notes.store');
        Route::post('/job-applications/{id}/reject', [RecruitingController::class, 'reject'])->name('job-applications.reject');
        Route::post('/job-applications/{id}/consent', [RecruitingController::class, 'recordConsent'])->name('job-applications.consent');
        Route::post('/job-applications/{id}/hire', [RecruitingController::class, 'hire'])->name('job-applications.hire');
        Route::put('/recruiting-settings', [RecruitingController::class, 'saveSettings'])->name('recruiting-settings.save');

        // Onboarding
        Route::post('/onboarding-templates', [RecruitingController::class, 'storeTemplate'])->name('onboarding-templates.store');
        Route::get('/onboarding-checklists', [RecruitingController::class, 'checklists'])->name('onboarding-checklists.index');
        Route::post('/onboarding-tasks/{id}/complete', [RecruitingController::class, 'completeTask'])->name('onboarding-tasks.complete');
    });

// Employee self-service: no module role, each answer is limited to the authenticated user.
Route::prefix('api/v1/hr/time')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])
    ->name('api.v1.hr.time.')
    ->group(function (): void {
        Route::get('/my-shifts', [ShiftPlanningController::class, 'myShifts'])->name('my-shifts');
        Route::get('/payroll-view', [TimeClockDetailsController::class, 'payrollView'])->name('payroll-view');
        Route::get('/presence', [TimeClockDetailsController::class, 'presence'])->name('presence');
        Route::post('/punches/import', [TimeClockDetailsController::class, 'importPunches'])->name('punches.import');
        Route::get('/meal-bookings', [TimeClockDetailsController::class, 'mealBookings'])->name('meal-bookings.index');
        Route::post('/meal-bookings', [TimeClockDetailsController::class, 'storeMealBooking'])->name('meal-bookings.store');
    });
