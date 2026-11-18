<?php

declare(strict_types=1);

use Enterprise\MultiTenant\Http\Middleware\EnsureModuleAccess;
use Illuminate\Support\Facades\Route;
use Modules\HumanResources\Http\Controllers\EmployeeController;
use Modules\HumanResources\Http\Controllers\EmployeeDocumentController;
use Modules\HumanResources\Http\Controllers\EmployeeSelfServiceController;
use Modules\HumanResources\Http\Controllers\EmploymentContractController;
use Modules\HumanResources\Http\Controllers\LeaveAndTimeController;
use Modules\HumanResources\Http\Controllers\LeaveManagementController;
use Modules\HumanResources\Http\Controllers\OrganizationController;
use Modules\HumanResources\Http\Controllers\Recruiting\CandidateApplicationController;
use Modules\HumanResources\Http\Controllers\Recruiting\InterviewScorecardController;
use Modules\HumanResources\Http\Controllers\Recruiting\JobOfferController;
use Modules\HumanResources\Http\Controllers\Recruiting\JobOpeningController;
use Modules\HumanResources\Http\Controllers\Recruiting\PublicCareersController;
use Modules\HumanResources\Http\Controllers\Recruiting\RecruitingEmailTemplateController;
use Modules\HumanResources\Http\Controllers\Recruiting\RecruitingSettingsController;
use Modules\HumanResources\Http\Controllers\TalentAndTrainingController;
use Modules\HumanResources\Http\Controllers\TimeTrackingController;
use Modules\HumanResources\Http\Controllers\WorkingTimeRulesController;

// Employee self-service: every member reaches only the records of the employee linked to the own user, so these
// routes need no HR module access (the controller resolves the own employee record and answers 403 without one).
Route::prefix('api/v1/hr/me')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])
    ->name('api.v1.hr.me.')
    ->whereUuid('id')
    ->group(function (): void {
        Route::get('/', [EmployeeSelfServiceController::class, 'profile'])->name('profile');
        Route::get('/leave-types', [EmployeeSelfServiceController::class, 'leaveTypes'])->name('leave-types');
        Route::get('/leave-balances', [EmployeeSelfServiceController::class, 'leaveBalances'])->name('leave-balances');
        Route::get('/leave-requests', [EmployeeSelfServiceController::class, 'leaveRequests'])->name('leave-requests.index');
        Route::post('/leave-requests', [EmployeeSelfServiceController::class, 'submitLeave'])->name('leave-requests.store');
        Route::post('/leave-requests/{id}/cancel', [EmployeeSelfServiceController::class, 'cancelLeave'])->name('leave-requests.cancel');
        Route::get('/training', [EmployeeSelfServiceController::class, 'training'])->name('training');
        Route::get('/documents', [EmployeeSelfServiceController::class, 'documents'])->name('documents.index');
        Route::get('/documents/{id}/download', [EmployeeSelfServiceController::class, 'downloadDocument'])->name('documents.download');
        Route::post('/clock', [EmployeeSelfServiceController::class, 'clock'])->name('clock');
        Route::get('/time/events', [EmployeeSelfServiceController::class, 'timeEvents'])->name('time.events');
        Route::get('/clock-settings', [EmployeeSelfServiceController::class, 'clockSettings'])->name('clock-settings');
    });
use Modules\HumanResources\Http\Middleware\ResolveCareersTenant;

Route::prefix('api/v1/hr')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:hr'])
    ->group(function (): void {
        // Employee Lifecycle Routes
        Route::middleware('privacy.sensitive.log:hr_employee_records')->group(function (): void {
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
        });

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

        // Swiss Working Time Recording Routes (LL, OLL 1)
        // Employees use their own time records: TimeTrackingPolicy decides who can see what.
        Route::prefix('time')->name('api.v1.hr.time.')->withoutMiddleware(EnsureModuleAccess::class)->group(function (): void {
            Route::get('/events', [TimeTrackingController::class, 'events'])->name('events.index');
            Route::post('/events', [TimeTrackingController::class, 'storeEvent'])->name('events.store');
            Route::patch('/events/{id}', [TimeTrackingController::class, 'correctEvent'])->whereUuid('id')->name('events.correct');
            Route::delete('/events/{id}', [TimeTrackingController::class, 'destroyEvent'])->whereUuid('id')->name('events.destroy');
            Route::put('/daily-records', [TimeTrackingController::class, 'saveDailyRecord'])->name('daily-records.save');
            Route::get('/report', [TimeTrackingController::class, 'report'])->name('report');
            Route::get('/audit', [TimeTrackingController::class, 'audit'])->name('audit');
            Route::get('/holidays', [TimeTrackingController::class, 'holidays'])->name('holidays');
            Route::get('/profiles/{userId}', [TimeTrackingController::class, 'profile'])->whereUuid('userId')->name('profiles.show');
            Route::put('/profiles/{userId}', [TimeTrackingController::class, 'saveProfile'])->whereUuid('userId')->name('profiles.save');
            Route::get('/overtime', [WorkingTimeRulesController::class, 'overtime'])->name('overtime');
            Route::get('/export', [TimeTrackingController::class, 'exportLegal'])->name('export');
            Route::get('/verify-integrity', [TimeTrackingController::class, 'verifyIntegrity'])->name('verify-integrity');
            Route::get('/compliance-alerts', [TimeTrackingController::class, 'complianceAlerts'])->name('compliance-alerts');
        });

        // Contract history and salary components
        Route::whereUuid('id')->group(function (): void {
            Route::put('/departments/{id}', [OrganizationController::class, 'updateDepartment'])->name('api.v1.hr.departments.update');
            Route::put('/positions/{id}', [OrganizationController::class, 'updatePosition'])->name('api.v1.hr.positions.update');

            Route::get('/employees/{id}/contracts', [EmploymentContractController::class, 'index'])->name('api.v1.hr.contracts.index');
            Route::post('/employees/{id}/contracts', [EmploymentContractController::class, 'store'])->name('api.v1.hr.contracts.store');
            Route::put('/contracts/{id}', [EmploymentContractController::class, 'update'])->name('api.v1.hr.contracts.update');
            Route::post('/contracts/{id}/salary-components', [EmploymentContractController::class, 'storeComponent'])->name('api.v1.hr.salary-components.store');
            Route::put('/salary-components/{id}', [EmploymentContractController::class, 'updateComponent'])->name('api.v1.hr.salary-components.update');
            Route::delete('/salary-components/{id}', [EmploymentContractController::class, 'destroyComponent'])->name('api.v1.hr.salary-components.destroy');

            // Personnel file
            Route::get('/employees/{id}/documents', [EmployeeDocumentController::class, 'index'])->name('api.v1.hr.employee-documents.index');
            Route::post('/employees/{id}/documents', [EmployeeDocumentController::class, 'store'])->name('api.v1.hr.employee-documents.store');
            Route::get('/employee-documents/{id}/download', [EmployeeDocumentController::class, 'download'])->name('api.v1.hr.employee-documents.download');
            Route::delete('/employee-documents/{id}', [EmployeeDocumentController::class, 'destroy'])->name('api.v1.hr.employee-documents.destroy');

            // Leave configuration and planning
            Route::post('/leave-types', [LeaveManagementController::class, 'storeType'])->name('api.v1.hr.leave-types.store');
            Route::put('/leave-types/{id}', [LeaveManagementController::class, 'updateType'])->name('api.v1.hr.leave-types.update');
            Route::get('/leave-accrual-policies', [LeaveManagementController::class, 'policies'])->name('api.v1.hr.leave-accrual-policies.index');
            Route::post('/leave-accrual-policies', [LeaveManagementController::class, 'storePolicy'])->name('api.v1.hr.leave-accrual-policies.store');
            Route::put('/leave-accrual-policies/{id}', [LeaveManagementController::class, 'updatePolicy'])->name('api.v1.hr.leave-accrual-policies.update');
            Route::delete('/leave-accrual-policies/{id}', [LeaveManagementController::class, 'destroyPolicy'])->name('api.v1.hr.leave-accrual-policies.destroy');
            Route::post('/leave-requests/{id}/cancel', [LeaveManagementController::class, 'cancel'])->name('api.v1.hr.leave-requests.cancel');
            Route::get('/leave-calendar', [LeaveManagementController::class, 'calendar'])->name('api.v1.hr.leave-calendar');

            // Overtime rules and monthly closing of the working time records
            Route::get('/overtime-rules', [WorkingTimeRulesController::class, 'rules'])->name('api.v1.hr.overtime-rules.index');
            Route::post('/overtime-rules', [WorkingTimeRulesController::class, 'storeRule'])->name('api.v1.hr.overtime-rules.store');
            Route::put('/overtime-rules/{id}', [WorkingTimeRulesController::class, 'updateRule'])->name('api.v1.hr.overtime-rules.update');
            Route::delete('/overtime-rules/{id}', [WorkingTimeRulesController::class, 'destroyRule'])->name('api.v1.hr.overtime-rules.destroy');
            Route::get('/time-closings', [WorkingTimeRulesController::class, 'closings'])->name('api.v1.hr.time-closings.index');
            Route::post('/time-closings', [WorkingTimeRulesController::class, 'close'])->name('api.v1.hr.time-closings.store');
            Route::post('/time-closings/{id}/reopen', [WorkingTimeRulesController::class, 'reopen'])->name('api.v1.hr.time-closings.reopen');
            Route::post('/time-closings/{year}/{month}/transfer-to-payroll', [WorkingTimeRulesController::class, 'transferToPayroll'])->whereNumber(['year', 'month'])->name('api.v1.hr.time-closings.transfer-to-payroll');
            Route::get('/time/night-work-balances', [WorkingTimeRulesController::class, 'nightWorkBalances'])->name('api.v1.hr.time.night-work-balances');
        });

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

// Recruiting & ATS (Mod 070) - authenticated, tenant scoped
Route::prefix('api/v1/hr/recruiting')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])
    ->name('api.v1.hr.recruiting.')
    ->group(function (): void {
        Route::get('/job-openings', [JobOpeningController::class, 'index'])->name('job-openings.index');
        Route::post('/job-openings', [JobOpeningController::class, 'store'])->name('job-openings.store');
        Route::get('/job-openings/{id}', [JobOpeningController::class, 'show'])->whereUuid('id')->name('job-openings.show');
        Route::put('/job-openings/{id}', [JobOpeningController::class, 'update'])->whereUuid('id')->name('job-openings.update');
        Route::post('/job-openings/{id}/publish', [JobOpeningController::class, 'publish'])->whereUuid('id')->name('job-openings.publish');
        Route::post('/job-openings/{id}/close', [JobOpeningController::class, 'close'])->whereUuid('id')->name('job-openings.close');
        Route::get('/job-openings/{id}/board', [JobOpeningController::class, 'board'])->whereUuid('id')->name('job-openings.board');

        Route::get('/applications', [CandidateApplicationController::class, 'index'])->name('applications.index');
        Route::post('/applications', [CandidateApplicationController::class, 'store'])->name('applications.store');
        Route::get('/applications/{id}', [CandidateApplicationController::class, 'show'])->whereUuid('id')->name('applications.show');
        Route::patch('/applications/{id}', [CandidateApplicationController::class, 'update'])->whereUuid('id')->name('applications.update');
        Route::get('/applications/{id}/cv', [CandidateApplicationController::class, 'cv'])->whereUuid('id')->name('applications.cv');
        Route::post('/applications/{id}/reparse-cv', [CandidateApplicationController::class, 'reparseCv'])->whereUuid('id')->name('applications.reparse-cv');
        Route::post('/applications/{id}/move', [CandidateApplicationController::class, 'move'])->whereUuid('id')->name('applications.move');
        Route::post('/applications/{id}/extend-consent', [CandidateApplicationController::class, 'extendConsent'])->whereUuid('id')->name('applications.extend-consent');
        Route::post('/applications/{id}/anonymize', [CandidateApplicationController::class, 'anonymize'])->whereUuid('id')->name('applications.anonymize');
        Route::post('/applications/{id}/hire', [CandidateApplicationController::class, 'hire'])->whereUuid('id')->name('applications.hire');
        Route::get('/applications/{id}/scorecards', [InterviewScorecardController::class, 'index'])->whereUuid('id')->name('applications.scorecards.index');
        Route::post('/applications/{id}/scorecards', [InterviewScorecardController::class, 'store'])->whereUuid('id')->name('applications.scorecards.store');
        Route::get('/applications/{id}/offer', [JobOfferController::class, 'show'])->whereUuid('id')->name('applications.offer.show');
        Route::post('/applications/{id}/offer', [JobOfferController::class, 'store'])->whereUuid('id')->name('applications.offer.store');

        Route::post('/offers/{id}/mark-signed', [JobOfferController::class, 'markSigned'])->whereUuid('id')->name('offers.mark-signed');
        Route::post('/offers/{id}/decline', [JobOfferController::class, 'decline'])->whereUuid('id')->name('offers.decline');

        Route::get('/email-templates', [RecruitingEmailTemplateController::class, 'index'])->middleware('module.access:hr')->name('email-templates.index');
        Route::put('/email-templates/{stage}/{locale}', [RecruitingEmailTemplateController::class, 'upsert'])->middleware('module.access:hr')->name('email-templates.upsert');

        Route::get('/settings', [RecruitingSettingsController::class, 'show'])->middleware('module.access:hr')->name('settings.show');
        Route::put('/settings', [RecruitingSettingsController::class, 'update'])->middleware('module.access:hr')->name('settings.update');
    });

// Recruiting public careers page and job feeds - no auth, tenant resolved from the careers slug only
Route::prefix('api/v1/public/careers/{careersSlug}')
    ->middleware(['api', 'throttle:60,1', ResolveCareersTenant::class])
    ->name('api.v1.public.careers.')
    ->group(function (): void {
        Route::get('/', [PublicCareersController::class, 'show'])->name('show');
        Route::get('/jobs', [PublicCareersController::class, 'jobs'])->name('jobs.index');
        Route::get('/jobs/{id}', [PublicCareersController::class, 'job'])->whereUuid('id')->name('jobs.show');
        Route::post('/jobs/{id}/apply', [PublicCareersController::class, 'apply'])->middleware('throttle:10,1,careers-apply')->whereUuid('id')->name('jobs.apply');
        Route::get('/feed.json', [PublicCareersController::class, 'feedJson'])->name('feed.json');
        Route::get('/feed.rss', [PublicCareersController::class, 'feedRss'])->name('feed.rss');
    });
