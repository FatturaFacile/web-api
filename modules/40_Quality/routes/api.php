<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Quality\Http\Controllers\AuditController;
use Modules\Quality\Http\Controllers\CapaController;
use Modules\Quality\Http\Controllers\ComplaintController;
use Modules\Quality\Http\Controllers\InstrumentController;
use Modules\Quality\Http\Controllers\KpiController;
use Modules\Quality\Http\Controllers\NonconformityController;
use Modules\Quality\Http\Controllers\ProcedureController;
use Modules\Quality\Http\Controllers\SpcController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Quality management system. module.access:quality lets every member read (GET) and only the roles with write access of
| config/permissions.php change anything; the policy keeps the records inside the company and reserves the approval of
| procedures to owners, administrators and members granted quality.approve.
*/
Route::prefix('api/v1/quality')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:quality'])
    ->name('api.v1.quality.')
    ->where(['id' => $uuid, 'itemId' => $uuid])
    ->group(function (): void {
        Route::get('/kpis', [KpiController::class, 'show'])->name('kpis');

        Route::get('/spc/characteristics', [SpcController::class, 'index'])->name('spc.characteristics.index');
        Route::post('/spc/characteristics', [SpcController::class, 'store'])->name('spc.characteristics.store');
        Route::get('/spc/characteristics/{id}', [SpcController::class, 'show'])->name('spc.characteristics.show');
        Route::put('/spc/characteristics/{id}', [SpcController::class, 'update'])->name('spc.characteristics.update');
        Route::delete('/spc/characteristics/{id}', [SpcController::class, 'destroy'])->name('spc.characteristics.destroy');
        Route::get('/spc/characteristics/{id}/chart', [SpcController::class, 'chart'])->name('spc.characteristics.chart');
        Route::get('/spc/characteristics/{id}/samples', [SpcController::class, 'samples'])->name('spc.samples.index');
        Route::post('/spc/characteristics/{id}/samples', [SpcController::class, 'storeSample'])->name('spc.samples.store');
        Route::delete('/spc/samples/{id}', [SpcController::class, 'destroySample'])->name('spc.samples.destroy');

        Route::get('/instruments', [InstrumentController::class, 'index'])->name('instruments.index');
        Route::post('/instruments', [InstrumentController::class, 'store'])->name('instruments.store');
        Route::get('/instruments/{id}', [InstrumentController::class, 'show'])->name('instruments.show');
        Route::put('/instruments/{id}', [InstrumentController::class, 'update'])->name('instruments.update');
        Route::post('/instruments/{id}/retire', [InstrumentController::class, 'retire'])->name('instruments.retire');
        Route::get('/instruments/{id}/calibrations', [InstrumentController::class, 'calibrations'])->name('instruments.calibrations.index');
        Route::post('/instruments/{id}/calibrations', [InstrumentController::class, 'storeCalibration'])->name('instruments.calibrations.store');
        Route::get('/calibrations/{id}/impact', [InstrumentController::class, 'impact'])->name('calibrations.impact');

        Route::get('/complaints', [ComplaintController::class, 'index'])->name('complaints.index');
        Route::post('/complaints', [ComplaintController::class, 'store'])->name('complaints.store');
        Route::get('/complaints/{id}', [ComplaintController::class, 'show'])->name('complaints.show');
        Route::put('/complaints/{id}', [ComplaintController::class, 'update'])->name('complaints.update');
        Route::post('/complaints/{id}/investigate', [ComplaintController::class, 'investigate'])->name('complaints.investigate');
        Route::post('/complaints/{id}/root-cause', [ComplaintController::class, 'rootCause'])->name('complaints.root-cause');
        Route::post('/complaints/{id}/raise-nonconformity', [ComplaintController::class, 'raiseNonconformity'])->name('complaints.raise-nonconformity');
        Route::post('/complaints/{id}/link', [ComplaintController::class, 'link'])->name('complaints.link');
        Route::post('/complaints/{id}/reply', [ComplaintController::class, 'reply'])->name('complaints.reply');
        Route::post('/complaints/{id}/reject', [ComplaintController::class, 'reject'])->name('complaints.reject');
        Route::post('/complaints/{id}/close', [ComplaintController::class, 'close'])->name('complaints.close');

        Route::get('/nonconformities', [NonconformityController::class, 'index'])->name('nonconformities.index');
        Route::post('/nonconformities', [NonconformityController::class, 'store'])->name('nonconformities.store');
        Route::get('/nonconformities/{id}', [NonconformityController::class, 'show'])->name('nonconformities.show');
        Route::put('/nonconformities/{id}', [NonconformityController::class, 'update'])->name('nonconformities.update');
        Route::post('/nonconformities/{id}/investigate', [NonconformityController::class, 'investigate'])->name('nonconformities.investigate');
        Route::post('/nonconformities/{id}/close', [NonconformityController::class, 'close'])->name('nonconformities.close');
        Route::post('/nonconformities/{id}/cancel', [NonconformityController::class, 'cancel'])->name('nonconformities.cancel');

        Route::get('/capa-actions', [CapaController::class, 'index'])->name('capa.index');
        Route::post('/capa-actions', [CapaController::class, 'store'])->name('capa.store');
        Route::get('/capa-actions/{id}', [CapaController::class, 'show'])->name('capa.show');
        Route::put('/capa-actions/{id}', [CapaController::class, 'update'])->name('capa.update');
        Route::post('/capa-actions/{id}/start', [CapaController::class, 'start'])->name('capa.start');
        Route::post('/capa-actions/{id}/complete', [CapaController::class, 'complete'])->name('capa.complete');
        Route::post('/capa-actions/{id}/verify-effectiveness', [CapaController::class, 'verifyEffectiveness'])->name('capa.verify-effectiveness');
        Route::post('/capa-actions/{id}/cancel', [CapaController::class, 'cancel'])->name('capa.cancel');

        Route::get('/audits', [AuditController::class, 'index'])->name('audits.index');
        Route::post('/audits', [AuditController::class, 'store'])->name('audits.store');
        Route::get('/audits/{id}', [AuditController::class, 'show'])->name('audits.show');
        Route::put('/audits/{id}', [AuditController::class, 'update'])->name('audits.update');
        Route::post('/audits/{id}/start', [AuditController::class, 'start'])->name('audits.start');
        Route::post('/audits/{id}/complete', [AuditController::class, 'complete'])->name('audits.complete');
        Route::post('/audits/{id}/cancel', [AuditController::class, 'cancel'])->name('audits.cancel');
        Route::put('/audits/{id}/checklist/{itemId}', [AuditController::class, 'recordResult'])->name('audits.checklist.result');
        Route::post('/audits/{id}/findings', [AuditController::class, 'storeFinding'])->name('audits.findings.store');

        Route::get('/procedures', [ProcedureController::class, 'index'])->name('procedures.index');
        Route::post('/procedures', [ProcedureController::class, 'store'])->name('procedures.store');
        Route::get('/procedures/{id}', [ProcedureController::class, 'show'])->name('procedures.show');
        Route::put('/procedures/{id}', [ProcedureController::class, 'update'])->name('procedures.update');
        Route::post('/procedures/{id}/submit', [ProcedureController::class, 'submit'])->name('procedures.submit');
        Route::post('/procedures/{id}/approve', [ProcedureController::class, 'approve'])->name('procedures.approve');
        Route::post('/procedures/{id}/reject', [ProcedureController::class, 'reject'])->name('procedures.reject');
        Route::post('/procedures/{id}/revise', [ProcedureController::class, 'revise'])->name('procedures.revise');
        Route::post('/procedures/{id}/obsolete', [ProcedureController::class, 'obsolete'])->name('procedures.obsolete');
    });
