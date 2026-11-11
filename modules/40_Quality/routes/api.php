<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Quality\Http\Controllers\AuditController;
use Modules\Quality\Http\Controllers\CapaController;
use Modules\Quality\Http\Controllers\KpiController;
use Modules\Quality\Http\Controllers\NonconformityController;
use Modules\Quality\Http\Controllers\ProcedureController;

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
