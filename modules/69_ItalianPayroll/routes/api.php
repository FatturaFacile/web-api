<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ItalianPayroll\Presentation\Http\Controllers\ItalianPayrollController;

// italian_payroll: owners, administrators and payroll.* members run the monthly closing. italian_payroll_self: any employee
// lists and opens their own payslips (the vault handler matches the signed-in user to the employee).
Route::prefix('api/v1/italian-payroll')->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])->group(function (): void {
    Route::middleware('module.access:italian_payroll')->group(function (): void {
        Route::get('periods', [ItalianPayrollController::class, 'index'])->name('italian-payroll.periods.index');
        Route::post('periods', [ItalianPayrollController::class, 'store'])->name('italian-payroll.periods.store');
        Route::get('periods/{id}', [ItalianPayrollController::class, 'show'])->whereUuid('id')->name('italian-payroll.periods.show');
        Route::post('periods/{id}/validate', [ItalianPayrollController::class, 'validateAttendance'])->whereUuid('id')->name('italian-payroll.periods.validate');
        Route::post('periods/{id}/export', [ItalianPayrollController::class, 'exportAttendance'])->whereUuid('id')->name('italian-payroll.periods.export');
        Route::post('periods/{id}/cedolini', [ItalianPayrollController::class, 'importCedolini'])->whereUuid('id')->name('italian-payroll.periods.cedolini');
        Route::post('periods/{id}/accounting-import', [ItalianPayrollController::class, 'importAccounting'])->whereUuid('id')->name('italian-payroll.periods.accounting');
        Route::post('periods/{id}/sepa', [ItalianPayrollController::class, 'sepa'])->whereUuid('id')->name('italian-payroll.periods.sepa');
        Route::post('periods/{id}/mark-paid', [ItalianPayrollController::class, 'markPaid'])->whereUuid('id')->name('italian-payroll.periods.paid');
        Route::get('periods/{id}/f24', [ItalianPayrollController::class, 'f24'])->whereUuid('id')->name('italian-payroll.periods.f24');
        Route::get('periods/{id}/download-audit', [ItalianPayrollController::class, 'downloadAudit'])->whereUuid('id')->name('italian-payroll.periods.audit');
        Route::get('tfr-ledger', [ItalianPayrollController::class, 'tfrLedger'])->name('italian-payroll.tfr-ledger');
        Route::put('gl-accounts', [ItalianPayrollController::class, 'saveGlAccounts'])->name('italian-payroll.gl-accounts');
        Route::put('causal-mappings', [ItalianPayrollController::class, 'saveCausalMappings'])->name('italian-payroll.causal-mappings');
    });

    Route::middleware('module.access:italian_payroll_self')->group(function (): void {
        Route::get('my-payslips', [ItalianPayrollController::class, 'myPayslips'])->name('italian-payroll.my-payslips');
        Route::post('my-payslips/{distributionId}/open', [ItalianPayrollController::class, 'openMyPayslip'])->whereUuid('distributionId')->name('italian-payroll.my-payslips.open');
    });
});
