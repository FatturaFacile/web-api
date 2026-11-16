<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\HumanResources\Http\Controllers\EmployeeController;
use Modules\HumanResources\Http\Controllers\LeaveAndTimeController;
use Modules\HumanResources\Http\Controllers\OrganizationController;
use Modules\HumanResources\Http\Controllers\Payroll\PayrollProfileController;
use Modules\HumanResources\Http\Controllers\Payroll\PayrollReferenceDataController;
use Modules\HumanResources\Http\Controllers\Payroll\PayrollRunController;
use Modules\HumanResources\Http\Controllers\Payroll\SalaryDeclarationController;
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

        // Swiss Payroll (Swissdec-style) Routes
        Route::prefix('payroll')->name('api.v1.hr.payroll.')->group(function (): void {
            Route::get('/wage-types', [PayrollReferenceDataController::class, 'wageTypes'])->name('wage-types.index');
            Route::post('/wage-types', [PayrollReferenceDataController::class, 'storeWageType'])->name('wage-types.store');
            Route::put('/wage-types/{id}', [PayrollReferenceDataController::class, 'updateWageType'])->name('wage-types.update');

            Route::get('/rate-sets', [PayrollReferenceDataController::class, 'rateSets'])->name('rate-sets.index');
            Route::post('/rate-sets', [PayrollReferenceDataController::class, 'storeRateSet'])->name('rate-sets.store');

            Route::get('/withholding-tariffs', [PayrollReferenceDataController::class, 'withholdingTariffs'])->name('withholding-tariffs.index');
            Route::post('/withholding-tariffs', [PayrollReferenceDataController::class, 'storeWithholdingTariff'])->name('withholding-tariffs.store');
            Route::post('/withholding-tariffs/import', [PayrollReferenceDataController::class, 'importWithholdingTariffs'])->name('withholding-tariffs.import');

            Route::get('/employees/{id}/profile', [PayrollProfileController::class, 'show'])->name('profiles.show');
            Route::put('/employees/{id}/profile', [PayrollProfileController::class, 'upsert'])->name('profiles.upsert');

            Route::get('/runs', [PayrollRunController::class, 'index'])->name('runs.index');
            Route::post('/runs', [PayrollRunController::class, 'store'])->name('runs.store');
            Route::get('/runs/{id}', [PayrollRunController::class, 'show'])->name('runs.show');
            Route::put('/runs/{id}', [PayrollRunController::class, 'update'])->name('runs.update');
            Route::post('/runs/{id}/calculate', [PayrollRunController::class, 'calculate'])->name('runs.calculate');
            Route::post('/runs/{id}/approve', [PayrollRunController::class, 'approve'])->name('runs.approve');
            Route::post('/runs/{id}/close', [PayrollRunController::class, 'close'])->name('runs.close');
            Route::get('/runs/{id}/payslips', [PayrollRunController::class, 'payslips'])->name('runs.payslips');
            Route::get('/payslips/{id}', [PayrollRunController::class, 'showPayslip'])->name('payslips.show');

            Route::post('/declarations', [SalaryDeclarationController::class, 'store'])->name('declarations.store');
            Route::get('/declarations/{id}', [SalaryDeclarationController::class, 'show'])->name('declarations.show');
            Route::get('/declarations/{id}/download', [SalaryDeclarationController::class, 'download'])->name('declarations.download');
        });
    });
