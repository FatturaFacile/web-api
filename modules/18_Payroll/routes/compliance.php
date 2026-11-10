<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Payroll\Http\Controllers\PayrollComplianceController;
use Modules\Payroll\Http\Controllers\PayrollExtrasController;
use Modules\Payroll\Http\Controllers\ShortTimeController;
use Modules\Payroll\Models\PayrollRun;

// The link of a payslip mail: public, limited, and it answers only with the date of birth of the employee.
Route::post('api/v1/portal/payslips/{token}', [PayrollExtrasController::class, 'openPayslipLink'])
    ->middleware(['api', 'locale', 'throttle:20,1'])
    ->where('token', '[A-Za-z0-9]{1,128}')
    ->name('api.v1.portal.payslips.open');

// Read access is checked here too, before validation: a user without payroll rights learns nothing from a 422. The
// controllers then ask for the ability of each action.
Route::prefix('api/v1/payroll')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:payroll', 'can:viewAny,'.PayrollRun::class])
    ->name('api.v1.payroll.')
    ->where(['id' => '[0-9a-fA-F-]{36}', 'employeeId' => '[0-9a-fA-F-]{36}'])
    ->group(function (): void {
        // Legal parameters by topic
        Route::get('/legal-parameters', [PayrollComplianceController::class, 'legalParameters'])->name('legal-parameters.index');
        Route::put('/legal-parameters/{topic}/{year}', [PayrollComplianceController::class, 'saveLegalParameters'])
            ->whereNumber('year')
            ->name('legal-parameters.save');

        // Withholding tax statements
        Route::get('/withholding-statements', [PayrollComplianceController::class, 'withholdingStatement'])->name('withholding-statements.show');
        Route::post('/withholding-statements', [PayrollComplianceController::class, 'closeWithholdingStatement'])->name('withholding-statements.close');
        Route::get('/exports/withholding-statement', [PayrollComplianceController::class, 'exportWithholdingStatement'])->name('exports.withholding-statement');

        // Frontier workers
        Route::get('/frontier-workers', [PayrollComplianceController::class, 'frontierWorkers'])->name('frontier-workers.index');
        Route::put('/frontier-workers/{employeeId}/nights', [PayrollComplianceController::class, 'saveFrontierNights'])->name('frontier-workers.nights');
        Route::get('/exports/frontier-exchange', [PayrollComplianceController::class, 'exportFrontierExchange'])->name('exports.frontier-exchange');

        // Swiss work permit compliance & Swissdec ELM 5.0 / 6.0 XML export and XSD validation
        Route::middleware('privacy.sensitive.log:swissdec_exports')->group(function (): void {
            Route::get('/compliance/permit-alerts', [PayrollComplianceController::class, 'permitAlerts'])->name('compliance.permit-alerts');
            Route::get('/compliance/salary-declaration/xml', [PayrollComplianceController::class, 'salaryDeclarationXml'])->name('compliance.salary-declaration.xml');
            Route::post('/compliance/salary-declaration/validate', [PayrollComplianceController::class, 'validateSalaryDeclaration'])->name('compliance.salary-declaration.validate');
        });

        // Short-time work and bad weather
        Route::get('/short-time/cases', [ShortTimeController::class, 'cases'])->name('short-time.cases.index');
        Route::post('/short-time/cases', [ShortTimeController::class, 'storeCase'])->name('short-time.cases.store');
        Route::post('/short-time/cases/{id}/claims', [ShortTimeController::class, 'calculateClaim'])->name('short-time.claims.calculate');
        Route::get('/short-time/claims/{id}', [ShortTimeController::class, 'showClaim'])->name('short-time.claims.show');
        Route::post('/short-time/claims/{id}/submit', [ShortTimeController::class, 'submitClaim'])->name('short-time.claims.submit');
        Route::post('/short-time/claims/{id}/apply', [ShortTimeController::class, 'applyClaim'])->name('short-time.claims.apply');
        Route::post('/short-time/claims/{id}/reimbursement', [ShortTimeController::class, 'recordReimbursement'])->name('short-time.claims.reimbursement');
        Route::get('/short-time/claims/{id}/export', [ShortTimeController::class, 'exportClaim'])->name('short-time.claims.export');

        // Construction wages under a collective agreement
        Route::post('/construction/parameters', [ShortTimeController::class, 'storeConstructionParameters'])->name('construction.parameters.store');
        Route::put('/construction/calendar', [ShortTimeController::class, 'saveCalendar'])->name('construction.calendar.save');
        Route::get('/construction/hours', [ShortTimeController::class, 'constructionHours'])->name('construction.hours');
        Route::get('/construction/joint-commission-report', [ShortTimeController::class, 'jointCommissionReport'])->name('construction.joint-commission-report');
        Route::get('/exports/joint-commission-report', [ShortTimeController::class, 'exportJointCommissionReport'])->name('exports.joint-commission-report');

        // Salary declaration in ELM structure. Not certified: the transmission is a stub that sends nothing.
        Route::middleware('privacy.sensitive.log:swissdec_exports')->group(function (): void {
            Route::get('/swissdec/status', [PayrollExtrasController::class, 'swissdecStatus'])->name('swissdec.status');
            Route::get('/swissdec/declaration', [PayrollExtrasController::class, 'swissdecDeclaration'])->name('swissdec.declaration');
            Route::get('/swissdec/readiness', [PayrollExtrasController::class, 'swissdecReadiness'])->name('swissdec.readiness');
            Route::post('/swissdec/transmissions', [PayrollExtrasController::class, 'swissdecTransmit'])->name('swissdec.transmissions.store');
        });

        // Payslip delivery, payment files, reports
        Route::post('/runs/{id}/deliver-payslips', [PayrollExtrasController::class, 'deliverPayslips'])->name('runs.deliver-payslips');
        Route::post('/runs/{id}/payment-batches', [PayrollExtrasController::class, 'createPaymentBatches'])->name('runs.payment-batches');
        Route::get('/reports/labour-cost', [PayrollExtrasController::class, 'labourCost'])->name('reports.labour-cost');
        Route::get('/children/allowances', [PayrollExtrasController::class, 'childAllowances'])->name('children.allowances');
    });

// A hiring simulation saves nothing: payroll read access is enough.
Route::post('api/v1/payroll/hiring-simulations', [PayrollExtrasController::class, 'simulateHiring'])
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:payroll,read', 'can:viewAny,'.PayrollRun::class])
    ->name('api.v1.payroll.hiring-simulations.store');
