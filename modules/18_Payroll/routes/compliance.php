<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Payroll\Http\Controllers\PayrollComplianceController;
use Modules\Payroll\Http\Controllers\ShortTimeController;

Route::prefix('api/v1/payroll')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])
    ->name('api.v1.payroll.')
    ->where(['id' => '[0-9a-fA-F-]{36}', 'employeeId' => '[0-9a-fA-F-]{36}'])
    ->group(function (): void {
        // Legal parameters by topic
        Route::get('/legal-parameters', [PayrollComplianceController::class, 'legalParameters'])->name('legal-parameters.index');
        Route::put('/legal-parameters/{topic}/{year}', [PayrollComplianceController::class, 'saveLegalParameters'])
            ->whereIn('topic', ['frontier_italy', 'short_time_work', 'bad_weather', 'withholding_tax_statement'])
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
    });
