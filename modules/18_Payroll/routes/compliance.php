<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Payroll\Http\Controllers\PayrollComplianceController;

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
    });
