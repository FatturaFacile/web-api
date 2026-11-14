<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Governance\Http\Controllers\ActionController;
use Modules\Governance\Http\Controllers\AuditController;
use Modules\Governance\Http\Controllers\AuditPlanController;
use Modules\Governance\Http\Controllers\ControlController;
use Modules\Governance\Http\Controllers\DashboardController;
use Modules\Governance\Http\Controllers\FindingController;
use Modules\Governance\Http\Controllers\ObligationController;
use Modules\Governance\Http\Controllers\RiskController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Governance, risk and compliance. module.access:governance lets every member read (GET) and write; the policy keeps the
| records inside the company, reserves the register, the library, the plan and the calendar to owners, administrators,
| accountants and members granted governance.manage, lets the owner of an action work on it, and reserves the
| management decisions (accepting a risk, approving a plan, verifying an action) to governance.approve.
*/
Route::prefix('api/v1/governance')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:governance'])
    ->name('api.v1.governance.')
    ->where(['id' => $uuid])
    ->group(function (): void {
        Route::get('/dashboard', [DashboardController::class, 'show'])->name('dashboard');
        Route::get('/members', [DashboardController::class, 'members'])->name('members');

        Route::get('/risks', [RiskController::class, 'index'])->name('risks.index');
        Route::post('/risks', [RiskController::class, 'store'])->name('risks.store');
        Route::get('/risks/{id}', [RiskController::class, 'show'])->name('risks.show');
        Route::put('/risks/{id}', [RiskController::class, 'update'])->name('risks.update');
        Route::delete('/risks/{id}', [RiskController::class, 'destroy'])->name('risks.destroy');
        Route::post('/risks/{id}/transition', [RiskController::class, 'transition'])->name('risks.transition');
        Route::put('/risks/{id}/controls', [RiskController::class, 'syncControls'])->name('risks.controls');

        Route::get('/controls', [ControlController::class, 'index'])->name('controls.index');
        Route::post('/controls', [ControlController::class, 'store'])->name('controls.store');
        Route::get('/controls/{id}', [ControlController::class, 'show'])->name('controls.show');
        Route::put('/controls/{id}', [ControlController::class, 'update'])->name('controls.update');
        Route::get('/controls/{id}/tests', [ControlController::class, 'tests'])->name('controls.tests.index');
        Route::post('/controls/{id}/tests', [ControlController::class, 'recordTest'])->name('controls.tests.store');

        Route::get('/audit-plans', [AuditPlanController::class, 'index'])->name('audit-plans.index');
        Route::post('/audit-plans', [AuditPlanController::class, 'store'])->name('audit-plans.store');
        Route::get('/audit-plans/{id}', [AuditPlanController::class, 'show'])->name('audit-plans.show');
        Route::put('/audit-plans/{id}', [AuditPlanController::class, 'update'])->name('audit-plans.update');
        Route::post('/audit-plans/{id}/transition', [AuditPlanController::class, 'transition'])->name('audit-plans.transition');

        Route::get('/audits', [AuditController::class, 'index'])->name('audits.index');
        Route::post('/audits', [AuditController::class, 'store'])->name('audits.store');
        Route::get('/audits/{id}', [AuditController::class, 'show'])->name('audits.show');
        Route::put('/audits/{id}', [AuditController::class, 'update'])->name('audits.update');
        Route::post('/audits/{id}/transition', [AuditController::class, 'transition'])->name('audits.transition');

        Route::get('/findings', [FindingController::class, 'index'])->name('findings.index');
        Route::post('/findings', [FindingController::class, 'store'])->name('findings.store');
        Route::get('/findings/{id}', [FindingController::class, 'show'])->name('findings.show');
        Route::put('/findings/{id}', [FindingController::class, 'update'])->name('findings.update');
        Route::post('/findings/{id}/transition', [FindingController::class, 'transition'])->name('findings.transition');
        Route::post('/findings/{id}/actions', [FindingController::class, 'storeAction'])->name('findings.actions.store');

        Route::get('/actions', [ActionController::class, 'index'])->name('actions.index');
        Route::get('/actions/{id}', [ActionController::class, 'show'])->name('actions.show');
        Route::put('/actions/{id}', [ActionController::class, 'update'])->name('actions.update');
        Route::post('/actions/{id}/transition', [ActionController::class, 'transition'])->name('actions.transition');

        Route::get('/obligations', [ObligationController::class, 'index'])->name('obligations.index');
        Route::post('/obligations', [ObligationController::class, 'store'])->name('obligations.store');
        Route::get('/obligations/templates', [ObligationController::class, 'templates'])->name('obligations.templates');
        Route::post('/obligations/seed-templates', [ObligationController::class, 'seedTemplates'])->name('obligations.seed-templates');
        Route::get('/obligations/{id}', [ObligationController::class, 'show'])->name('obligations.show');
        Route::put('/obligations/{id}', [ObligationController::class, 'update'])->name('obligations.update');
        Route::post('/obligations/{id}/transition', [ObligationController::class, 'transition'])->name('obligations.transition');
        Route::post('/obligations/{id}/complete', [ObligationController::class, 'complete'])->name('obligations.complete');
    });
