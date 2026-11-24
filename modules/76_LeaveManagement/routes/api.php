<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\LeaveManagement\Http\Controllers\DepartmentCalendarController;
use Modules\LeaveManagement\Http\Controllers\HolidayCalendarController;
use Modules\LeaveManagement\Http\Controllers\LeaveAccrualLedgerController;
use Modules\LeaveManagement\Http\Controllers\LeaveEntitlementPolicyController;
use Modules\LeaveManagement\Http\Controllers\LeaveRequestController;

/*
|--------------------------------------------------------------------------
| Leave, Permits & Absences Management (Mod 074 / Issue #174)
|--------------------------------------------------------------------------
| CH (26 Cantons, Art. 329a/329b CO, IJM/KTG, LAINF/SUVA, IPG/EO) &
| IT (Ferie, ROL, Ex-Festività, INPS PUC, INAIL, Legge 104, Comporto).
*/
Route::prefix('api/v1/leave-management')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])
    ->group(function (): void {
        Route::middleware('module.access:leave_management,read')->group(function (): void {
            Route::get('holidays/catalog', [HolidayCalendarController::class, 'catalog'])->name('leave-management.holidays.catalog');
            Route::get('holiday-calendars', [HolidayCalendarController::class, 'index'])->name('leave-management.holiday-calendars.index');

            Route::get('policies', [LeaveEntitlementPolicyController::class, 'index'])->name('leave-management.policies.index');

            Route::get('ledgers', [LeaveAccrualLedgerController::class, 'index'])->name('leave-management.ledgers.index');
            Route::get('comporto-curtailment', [LeaveAccrualLedgerController::class, 'evaluateComportoOrCurtailment'])->name('leave-management.comporto-curtailment');
            Route::get('integrations/payroll-summary', [LeaveAccrualLedgerController::class, 'payrollSummary'])->name('leave-management.integrations.payroll-summary');

            Route::get('requests', [LeaveRequestController::class, 'index'])->name('leave-management.requests.index');
            Route::get('requests/{id}', [LeaveRequestController::class, 'show'])->whereUuid('id')->name('leave-management.requests.show');

            Route::get('department-calendar', [DepartmentCalendarController::class, 'gantt'])->name('leave-management.department-calendar');
            Route::get('staffing-thresholds', [DepartmentCalendarController::class, 'thresholds'])->name('leave-management.staffing-thresholds.index');
        });

        Route::middleware('module.access:leave_management')->group(function (): void {
            Route::post('holiday-calendars', [HolidayCalendarController::class, 'store'])->name('leave-management.holiday-calendars.store');
            Route::put('holiday-calendars/{id}', [HolidayCalendarController::class, 'update'])->whereUuid('id')->name('leave-management.holiday-calendars.update');
            Route::delete('holiday-calendars/{id}', [HolidayCalendarController::class, 'destroy'])->whereUuid('id')->name('leave-management.holiday-calendars.destroy');

            Route::post('policies', [LeaveEntitlementPolicyController::class, 'store'])->name('leave-management.policies.store');
            Route::put('policies/{id}', [LeaveEntitlementPolicyController::class, 'update'])->whereUuid('id')->name('leave-management.policies.update');
            Route::delete('policies/{id}', [LeaveEntitlementPolicyController::class, 'destroy'])->whereUuid('id')->name('leave-management.policies.destroy');

            Route::post('ledgers/calculate-monthly', [LeaveAccrualLedgerController::class, 'calculateMonthly'])->name('leave-management.ledgers.calculate-monthly');

            Route::post('requests', [LeaveRequestController::class, 'store'])->name('leave-management.requests.store');
            Route::post('requests/sick-inps', [LeaveRequestController::class, 'registerSickInps'])->name('leave-management.requests.sick-inps');
            Route::post('requests/{id}/approve', [LeaveRequestController::class, 'approve'])->whereUuid('id')->name('leave-management.requests.approve');
            Route::post('requests/{id}/reject', [LeaveRequestController::class, 'reject'])->whereUuid('id')->name('leave-management.requests.reject');
            Route::post('requests/{id}/cancel', [LeaveRequestController::class, 'cancel'])->whereUuid('id')->name('leave-management.requests.cancel');

            Route::put('staffing-thresholds/{departmentId}', [DepartmentCalendarController::class, 'upsertThreshold'])->whereUuid('departmentId')->name('leave-management.staffing-thresholds.upsert');
        });
    });
