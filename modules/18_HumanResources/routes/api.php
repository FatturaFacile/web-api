<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\HumanResources\Http\Controllers\EmployeeController;
use Modules\HumanResources\Http\Controllers\LeaveAndTimeController;
use Modules\HumanResources\Http\Controllers\OrganizationController;
use Modules\HumanResources\Http\Controllers\TalentAndTrainingController;

Route::prefix('api/v1/hr')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        // Employee Lifecycle Routes
        Route::get('/employees', [EmployeeController::class, 'index'])->name('api.v1.hr.employees.index');
        Route::post('/employees', [EmployeeController::class, 'store'])->name('api.v1.hr.employees.store');
        Route::get('/employees/{id}', [EmployeeController::class, 'show'])->name('api.v1.hr.employees.show');
        Route::put('/employees/{id}', [EmployeeController::class, 'update'])->name('api.v1.hr.employees.update');
        Route::delete('/employees/{id}', [EmployeeController::class, 'destroy'])->name('api.v1.hr.employees.destroy');
        Route::post('/employees/{id}/complete-probation', [EmployeeController::class, 'completeProbation'])->name('api.v1.hr.employees.complete-probation');
        Route::post('/employees/{id}/promote', [EmployeeController::class, 'promote'])->name('api.v1.hr.employees.promote');
        Route::post('/employees/{id}/transfer', [EmployeeController::class, 'transfer'])->name('api.v1.hr.employees.transfer');
        Route::post('/employees/{id}/terminate', [EmployeeController::class, 'terminate'])->name('api.v1.hr.employees.terminate');
        Route::post('/employees/{id}/rehire', [EmployeeController::class, 'rehire'])->name('api.v1.hr.employees.rehire');

        // Organizational Management Routes
        Route::get('/departments', [OrganizationController::class, 'departments'])->name('api.v1.hr.departments.index');
        Route::post('/departments', [OrganizationController::class, 'storeDepartment'])->name('api.v1.hr.departments.store');
        Route::get('/positions', [OrganizationController::class, 'positions'])->name('api.v1.hr.positions.index');
        Route::post('/positions', [OrganizationController::class, 'storePosition'])->name('api.v1.hr.positions.store');
        Route::patch('/positions/{positionId}/successor', [OrganizationController::class, 'assignSuccessor'])->name('api.v1.hr.positions.successor');
        Route::get('/org-chart', [OrganizationController::class, 'orgChart'])->name('api.v1.hr.org-chart');
        Route::post('/payroll-forecast', [OrganizationController::class, 'payrollForecast'])->name('api.v1.hr.payroll-forecast');

        // Leave & Time Management Routes
        Route::get('/leave-types', [LeaveAndTimeController::class, 'leaveTypes'])->name('api.v1.hr.leave-types.index');
        Route::get('/leave-balances', [LeaveAndTimeController::class, 'leaveBalances'])->name('api.v1.hr.leave-balances.index');
        Route::post('/leave-balances/accrue', [LeaveAndTimeController::class, 'runAccruals'])->name('api.v1.hr.leave-balances.accrue');
        Route::get('/leave-requests', [LeaveAndTimeController::class, 'leaveRequests'])->name('api.v1.hr.leave-requests.index');
        Route::post('/leave-requests', [LeaveAndTimeController::class, 'submitLeave'])->name('api.v1.hr.leave-requests.store');
        Route::post('/leave-requests/{id}/approve', [LeaveAndTimeController::class, 'approveLeave'])->name('api.v1.hr.leave-requests.approve');
        Route::post('/leave-requests/{id}/reject', [LeaveAndTimeController::class, 'rejectLeave'])->name('api.v1.hr.leave-requests.reject');
        Route::get('/timesheets', [LeaveAndTimeController::class, 'timesheets'])->name('api.v1.hr.timesheets.index');
        Route::post('/timesheets', [LeaveAndTimeController::class, 'submitTimesheet'])->name('api.v1.hr.timesheets.store');
        Route::post('/timesheets/{id}/approve', [LeaveAndTimeController::class, 'approveTimesheet'])->name('api.v1.hr.timesheets.approve');

        // Talent, Performance & Training Routes
        Route::get('/performance-reviews', [TalentAndTrainingController::class, 'reviews'])->name('api.v1.hr.performance-reviews.index');
        Route::post('/performance-reviews', [TalentAndTrainingController::class, 'submitReview'])->name('api.v1.hr.performance-reviews.store');
        Route::post('/performance-reviews/{id}/complete', [TalentAndTrainingController::class, 'completeReview'])->name('api.v1.hr.performance-reviews.complete');
        Route::get('/training-courses', [TalentAndTrainingController::class, 'courses'])->name('api.v1.hr.training-courses.index');
        Route::post('/training-courses', [TalentAndTrainingController::class, 'storeCourse'])->name('api.v1.hr.training-courses.store');
        Route::get('/training-records', [TalentAndTrainingController::class, 'trainingRecords'])->name('api.v1.hr.training-records.index');
        Route::post('/training-records', [TalentAndTrainingController::class, 'assignTraining'])->name('api.v1.hr.training-records.store');
        Route::post('/training-records/{id}/complete', [TalentAndTrainingController::class, 'completeTraining'])->name('api.v1.hr.training-records.complete');
        Route::get('/certifications', [TalentAndTrainingController::class, 'certifications'])->name('api.v1.hr.certifications.index');
        Route::post('/certifications/scan-expiring', [TalentAndTrainingController::class, 'scanExpiringCertifications'])->name('api.v1.hr.certifications.scan-expiring');
    });
