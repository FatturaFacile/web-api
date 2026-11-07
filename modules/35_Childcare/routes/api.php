<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Childcare\Http\Controllers\AttendanceController;
use Modules\Childcare\Http\Controllers\CareController;
use Modules\Childcare\Http\Controllers\ChildcareBillingController;
use Modules\Childcare\Http\Controllers\ChildController;
use Modules\Childcare\Http\Controllers\ChildDocumentController;
use Modules\Childcare\Http\Controllers\FamilyPortalController;
use Modules\Childcare\Http\Middleware\AuthenticateFamilyPortal;

$uuid = '[0-9a-fA-F-]{36}';

Route::prefix('api/v1/childcare')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:childcare'])
    ->name('api.v1.childcare.')
    ->where(['id' => $uuid])
    ->group(function (): void {
        Route::get('/groups', [ChildController::class, 'groups'])->name('groups.index');
        Route::post('/groups', [ChildController::class, 'storeGroup'])->name('groups.store');
        Route::get('/children', [ChildController::class, 'index'])->name('children.index');
        Route::post('/children', [ChildController::class, 'store'])->name('children.store');
        Route::get('/children/{id}', [ChildController::class, 'show'])->name('children.show');
        Route::put('/children/{id}', [ChildController::class, 'update'])->name('children.update');
        Route::post('/children/{id}/reference-persons', [ChildController::class, 'storeReferencePerson'])->name('children.reference-persons.store');
        Route::post('/reference-persons/{id}/portal-access', [ChildController::class, 'issuePortalAccess'])->name('reference-persons.portal-access');
        Route::post('/children/{id}/weekly-plans', [ChildController::class, 'storeWeeklyPlan'])->name('children.weekly-plans.store');
        Route::post('/children/{id}/fees', [ChildController::class, 'storeFee'])->name('children.fees.store');

        Route::post('/attendances/check-in', [AttendanceController::class, 'checkIn'])->name('attendances.check-in');
        Route::post('/attendances/check-out', [AttendanceController::class, 'checkOut'])->name('attendances.check-out');
        Route::post('/attendances/absence', [AttendanceController::class, 'absence'])->name('attendances.absence');
        Route::get('/attendances/register', [AttendanceController::class, 'register'])->name('attendances.register');
        Route::put('/attendances/{id}', [AttendanceController::class, 'correct'])->name('attendances.correct');
        Route::get('/attendances/{id}/corrections', [AttendanceController::class, 'corrections'])->name('attendances.corrections');

        Route::get('/notes', [CareController::class, 'notes'])->name('notes.index');
        Route::post('/notes', [CareController::class, 'storeNote'])->name('notes.store');
        Route::get('/children/{id}/daily-report', [CareController::class, 'dailyReport'])->name('children.daily-report');
        Route::get('/meals', [CareController::class, 'meals'])->name('meals');
        Route::post('/staff-shifts', [CareController::class, 'storeShift'])->name('staff-shifts.store');
        Route::get('/staff-hours', [CareController::class, 'staffHours'])->name('staff-hours');
        Route::get('/activities', [CareController::class, 'activities'])->name('activities.index');
        Route::post('/activities', [CareController::class, 'storeActivity'])->name('activities.store');
        Route::post('/activities/{id}/enrolments', [CareController::class, 'enrol'])->name('activities.enrol');
        Route::get('/children/{id}/messages', [CareController::class, 'messages'])->name('children.messages.index');
        Route::post('/children/{id}/messages', [CareController::class, 'storeMessage'])->name('children.messages.store');

        Route::get('/document-requirements', [ChildDocumentController::class, 'requirements'])->name('document-requirements.index');
        Route::post('/document-requirements', [ChildDocumentController::class, 'storeRequirement'])->name('document-requirements.store');
        Route::get('/documents/outstanding', [ChildDocumentController::class, 'outstanding'])->name('documents.outstanding');
        Route::get('/documents/{id}/download', [ChildDocumentController::class, 'download'])->name('documents.download');
        Route::get('/children/{id}/documents', [ChildDocumentController::class, 'index'])->name('children.documents.index');
        Route::post('/children/{id}/documents', [ChildDocumentController::class, 'store'])->name('children.documents.store');

        Route::get('/fee-plans', [ChildcareBillingController::class, 'feePlans'])->name('fee-plans.index');
        Route::post('/fee-plans', [ChildcareBillingController::class, 'storeFeePlan'])->name('fee-plans.store');
        Route::get('/subsidy-rules', [ChildcareBillingController::class, 'subsidyRules'])->name('subsidy-rules.index');
        Route::post('/subsidy-rules', [ChildcareBillingController::class, 'storeSubsidyRule'])->name('subsidy-rules.store');
        Route::post('/subsidy-rules/{id}/verify', [ChildcareBillingController::class, 'verifySubsidyRule'])->name('subsidy-rules.verify');
        Route::post('/billing/runs', [ChildcareBillingController::class, 'run'])->name('billing.run');
        Route::get('/billing/bills', [ChildcareBillingController::class, 'bills'])->name('bills.index');
        Route::get('/billing/subsidies/export', [ChildcareBillingController::class, 'exportSubsidies'])->name('subsidies.export');
    });

Route::prefix('api/v1/childcare-family')
    ->middleware(['api', 'locale', 'throttle:60,1', AuthenticateFamilyPortal::class])
    ->name('api.v1.childcare-family.')
    ->where(['id' => $uuid])
    ->group(function (): void {
        Route::get('/children', [FamilyPortalController::class, 'children'])->name('children');
        Route::get('/children/{id}/attendance', [FamilyPortalController::class, 'attendance'])->name('attendance');
        Route::get('/children/{id}/daily-report', [FamilyPortalController::class, 'dailyReport'])->name('daily-report');
        Route::get('/invoices', [FamilyPortalController::class, 'invoices'])->name('invoices');
        Route::get('/documents', [FamilyPortalController::class, 'documents'])->name('documents');
        Route::get('/documents/{id}/download', [FamilyPortalController::class, 'download'])->name('documents.download');
        Route::post('/documents/{id}/sign', [FamilyPortalController::class, 'sign'])->name('documents.sign');
        Route::get('/messages', [FamilyPortalController::class, 'messages'])->name('messages');
        Route::post('/messages', [FamilyPortalController::class, 'storeMessage'])->name('messages.store');
    });
