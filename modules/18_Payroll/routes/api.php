<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Payroll\Http\Controllers\CollectiveAgreementController;
use Modules\Payroll\Http\Controllers\EmployeePayrollDataController;
use Modules\Payroll\Http\Controllers\PayrollExportController;
use Modules\Payroll\Http\Controllers\PayrollLegalTablesController;
use Modules\Payroll\Http\Controllers\PayrollRunController;
use Modules\Payroll\Http\Controllers\PayrollSettingsController;
use Modules\Payroll\Http\Controllers\PayslipController;
use Modules\Payroll\Http\Controllers\SalaryCertificateController;
use Modules\Payroll\Http\Controllers\WageTypeController;

Route::prefix('api/v1/payroll')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])
    ->name('api.v1.payroll.')
    ->where(['id' => '[0-9a-fA-F-]{36}', 'levelId' => '[0-9a-fA-F-]{36}', 'employeeId' => '[0-9a-fA-F-]{36}'])
    ->group(function (): void {
        // Monthly runs
        Route::get('/runs', [PayrollRunController::class, 'index'])->name('runs.index');
        Route::post('/runs/calculate', [PayrollRunController::class, 'calculate'])->name('runs.calculate');
        Route::post('/runs/simulate', [PayrollRunController::class, 'simulate'])->name('runs.simulate');
        Route::post('/runs/preview', [PayrollRunController::class, 'preview'])->name('runs.preview');
        Route::get('/runs/{id}', [PayrollRunController::class, 'show'])->name('runs.show');
        Route::post('/runs/{id}/finalize', [PayrollRunController::class, 'finalize'])->name('runs.finalize');
        Route::post('/runs/{id}/reopen', [PayrollRunController::class, 'reopen'])->name('runs.reopen');
        Route::get('/runs/{id}/labour-book', [PayrollRunController::class, 'labourBook'])->name('runs.labour-book');

        // Payslips and salary certificates
        Route::get('/payslips', [PayslipController::class, 'index'])->name('payslips.index');
        Route::get('/payslips/{id}', [PayslipController::class, 'show'])->name('payslips.show');
        Route::get('/payslips/{id}/pdf', [PayslipController::class, 'pdf'])->name('payslips.pdf');
        Route::get('/salary-certificates', [SalaryCertificateController::class, 'index'])->name('salary-certificates.index');
        Route::post('/salary-certificates/generate', [SalaryCertificateController::class, 'generate'])->name('salary-certificates.generate');
        Route::get('/salary-certificates/{id}', [SalaryCertificateController::class, 'show'])->name('salary-certificates.show');
        Route::get('/salary-certificates/{id}/pdf', [SalaryCertificateController::class, 'pdf'])->name('salary-certificates.pdf');

        // Filing exports
        Route::get('/exports/salary-declaration', [PayrollExportController::class, 'salaryDeclaration'])->name('exports.salary-declaration');
        Route::get('/exports/f24', [PayrollExportController::class, 'f24'])->name('exports.f24');
        Route::get('/exports/uniemens', [PayrollExportController::class, 'uniEmens'])->name('exports.uniemens');
        Route::get('/exports/cu', [PayrollExportController::class, 'certificazioneUnica'])->name('exports.cu');

        // Settings and setup
        Route::post('/setup', [PayrollSettingsController::class, 'setup'])->name('setup');
        Route::get('/settings/swiss', [PayrollSettingsController::class, 'showSwiss'])->name('settings.swiss.show');
        Route::put('/settings/swiss', [PayrollSettingsController::class, 'updateSwiss'])->name('settings.swiss.update');
        Route::get('/settings/italian', [PayrollSettingsController::class, 'showItalian'])->name('settings.italian.show');
        Route::put('/settings/italian', [PayrollSettingsController::class, 'updateItalian'])->name('settings.italian.update');

        // Collective agreements
        Route::get('/collective-agreements', [CollectiveAgreementController::class, 'index'])->name('collective-agreements.index');
        Route::post('/collective-agreements', [CollectiveAgreementController::class, 'store'])->name('collective-agreements.store');
        Route::get('/collective-agreements/{id}', [CollectiveAgreementController::class, 'show'])->name('collective-agreements.show');
        Route::put('/collective-agreements/{id}', [CollectiveAgreementController::class, 'update'])->name('collective-agreements.update');
        Route::delete('/collective-agreements/{id}', [CollectiveAgreementController::class, 'destroy'])->name('collective-agreements.destroy');
        Route::post('/collective-agreements/{id}/levels', [CollectiveAgreementController::class, 'storeLevel'])->name('collective-agreements.levels.store');
        Route::put('/collective-agreements/{id}/levels/{levelId}', [CollectiveAgreementController::class, 'updateLevel'])->name('collective-agreements.levels.update');
        Route::delete('/collective-agreements/{id}/levels/{levelId}', [CollectiveAgreementController::class, 'destroyLevel'])->name('collective-agreements.levels.destroy');

        // Wage types
        Route::get('/wage-types', [WageTypeController::class, 'index'])->name('wage-types.index');
        Route::post('/wage-types', [WageTypeController::class, 'store'])->name('wage-types.store');
        Route::put('/wage-types/{id}', [WageTypeController::class, 'update'])->name('wage-types.update');
        Route::delete('/wage-types/{id}', [WageTypeController::class, 'destroy'])->name('wage-types.destroy');

        // Employee data of the payroll
        Route::get('/employee-profiles', [EmployeePayrollDataController::class, 'profiles'])->name('employee-profiles.index');
        Route::get('/employee-profiles/{employeeId}', [EmployeePayrollDataController::class, 'showProfile'])->name('employee-profiles.show');
        Route::put('/employee-profiles/{employeeId}', [EmployeePayrollDataController::class, 'saveProfile'])->name('employee-profiles.save');
        Route::get('/inputs', [EmployeePayrollDataController::class, 'inputs'])->name('inputs.index');
        Route::post('/inputs', [EmployeePayrollDataController::class, 'storeInput'])->name('inputs.store');
        Route::put('/inputs/{id}', [EmployeePayrollDataController::class, 'updateInput'])->name('inputs.update');
        Route::delete('/inputs/{id}', [EmployeePayrollDataController::class, 'destroyInput'])->name('inputs.destroy');
        Route::get('/absences', [EmployeePayrollDataController::class, 'absences'])->name('absences.index');
        Route::post('/absences', [EmployeePayrollDataController::class, 'storeAbsence'])->name('absences.store');
        Route::put('/absences/{id}', [EmployeePayrollDataController::class, 'updateAbsence'])->name('absences.update');
        Route::delete('/absences/{id}', [EmployeePayrollDataController::class, 'destroyAbsence'])->name('absences.destroy');
        Route::get('/children', [EmployeePayrollDataController::class, 'children'])->name('children.index');
        Route::post('/children', [EmployeePayrollDataController::class, 'storeChild'])->name('children.store');
        Route::put('/children/{id}', [EmployeePayrollDataController::class, 'updateChild'])->name('children.update');
        Route::delete('/children/{id}', [EmployeePayrollDataController::class, 'destroyChild'])->name('children.destroy');

        // Legal tables
        Route::get('/annual-parameters', [PayrollLegalTablesController::class, 'annualParameters'])->name('annual-parameters.index');
        Route::put('/annual-parameters/{country}/{year}', [PayrollLegalTablesController::class, 'saveAnnualParameters'])->whereNumber('year')->name('annual-parameters.save');
        Route::get('/withholding-tax-tariffs', [PayrollLegalTablesController::class, 'tariffSummary'])->name('withholding-tax-tariffs.index');
        Route::post('/withholding-tax-tariffs/import', [PayrollLegalTablesController::class, 'importTariffs'])->name('withholding-tax-tariffs.import');
        Route::get('/family-allowance-rates', [PayrollLegalTablesController::class, 'familyAllowanceRates'])->name('family-allowance-rates.index');
        Route::post('/family-allowance-rates', [PayrollLegalTablesController::class, 'storeFamilyAllowanceRate'])->name('family-allowance-rates.store');
        Route::put('/family-allowance-rates/{id}', [PayrollLegalTablesController::class, 'updateFamilyAllowanceRate'])->name('family-allowance-rates.update');
        Route::delete('/family-allowance-rates/{id}', [PayrollLegalTablesController::class, 'destroyFamilyAllowanceRate'])->name('family-allowance-rates.destroy');
    });
