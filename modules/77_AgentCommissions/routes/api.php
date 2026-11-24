<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\AgentCommissions\Http\Controllers\AgentController;
use Modules\AgentCommissions\Http\Controllers\CommissionRuleController;
use Modules\AgentCommissions\Http\Controllers\EnasarcoController;
use Modules\AgentCommissions\Http\Controllers\ExportController;
use Modules\AgentCommissions\Http\Controllers\FiscalParameterController;
use Modules\AgentCommissions\Http\Controllers\LedgerController;
use Modules\AgentCommissions\Http\Controllers\LookupController;
use Modules\AgentCommissions\Http\Controllers\StatementController;
use Modules\AgentCommissions\Http\Controllers\ZoneController;

/*
|--------------------------------------------------------------------------
| Sales commissions and agent management (Mod 041 / Issue #338)
|--------------------------------------------------------------------------
| Multi-level commission rules (product line / customer / zone), invoiced vs collected accrual with bank-reconciliation
| lock, Enasarco + FIRR engine, commission statements, liquidation, CU and Enasarco exports.
*/
Route::prefix('api/v1/agent-commissions')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:agent_commissions'])
    ->group(function (): void {
        Route::get('lookups', [LookupController::class, 'index'])->name('agent-commissions.lookups');

        Route::get('representatives/available', [AgentController::class, 'representatives'])->name('agent-commissions.representatives.available');
        Route::get('agents', [AgentController::class, 'index'])->name('agent-commissions.agents.index');
        Route::post('agents', [AgentController::class, 'store'])->name('agent-commissions.agents.store');
        Route::get('agents/{id}', [AgentController::class, 'show'])->whereUuid('id')->name('agent-commissions.agents.show');
        Route::put('agents/{id}', [AgentController::class, 'update'])->whereUuid('id')->name('agent-commissions.agents.update');
        Route::delete('agents/{id}', [AgentController::class, 'destroy'])->whereUuid('id')->name('agent-commissions.agents.destroy');

        Route::get('zones', [ZoneController::class, 'index'])->name('agent-commissions.zones.index');
        Route::post('zones', [ZoneController::class, 'store'])->name('agent-commissions.zones.store');
        Route::put('zones/{id}', [ZoneController::class, 'update'])->whereUuid('id')->name('agent-commissions.zones.update');
        Route::delete('zones/{id}', [ZoneController::class, 'destroy'])->whereUuid('id')->name('agent-commissions.zones.destroy');

        Route::get('rules/resolve', [CommissionRuleController::class, 'resolve'])->name('agent-commissions.rules.resolve');
        Route::get('rules', [CommissionRuleController::class, 'index'])->name('agent-commissions.rules.index');
        Route::post('rules', [CommissionRuleController::class, 'store'])->name('agent-commissions.rules.store');
        Route::put('rules/{id}', [CommissionRuleController::class, 'update'])->whereUuid('id')->name('agent-commissions.rules.update');
        Route::delete('rules/{id}', [CommissionRuleController::class, 'destroy'])->whereUuid('id')->name('agent-commissions.rules.destroy');

        Route::get('fiscal-parameters', [FiscalParameterController::class, 'index'])->name('agent-commissions.fiscal-parameters.index');
        Route::put('fiscal-parameters', [FiscalParameterController::class, 'store'])->name('agent-commissions.fiscal-parameters.save');
        Route::delete('fiscal-parameters/{id}', [FiscalParameterController::class, 'destroy'])->whereUuid('id')->name('agent-commissions.fiscal-parameters.destroy');

        Route::get('ledger', [LedgerController::class, 'index'])->name('agent-commissions.ledger.index');
        Route::get('ledger/{id}', [LedgerController::class, 'show'])->whereUuid('id')->name('agent-commissions.ledger.show');
        Route::post('sync', [LedgerController::class, 'sync'])->name('agent-commissions.sync');

        Route::get('enasarco/overview', [EnasarcoController::class, 'overview'])->name('agent-commissions.enasarco.overview');

        Route::get('statements', [StatementController::class, 'index'])->name('agent-commissions.statements.index');
        Route::post('statements/preview', [StatementController::class, 'preview'])->name('agent-commissions.statements.preview');
        Route::post('statements', [StatementController::class, 'store'])->name('agent-commissions.statements.store');
        Route::get('statements/{id}', [StatementController::class, 'show'])->whereUuid('id')->name('agent-commissions.statements.show');
        Route::get('statements/{id}/pdf', [StatementController::class, 'pdf'])->whereUuid('id')->name('agent-commissions.statements.pdf');
        Route::post('statements/{id}/approve', [StatementController::class, 'approve'])->whereUuid('id')->name('agent-commissions.statements.approve');
        Route::post('statements/{id}/void', [StatementController::class, 'void'])->whereUuid('id')->name('agent-commissions.statements.void');
        Route::post('statements/{id}/self-billing', [StatementController::class, 'selfBilling'])->whereUuid('id')->name('agent-commissions.statements.self-billing');
        Route::post('statements/{id}/pay', [StatementController::class, 'pay'])->whereUuid('id')->name('agent-commissions.statements.pay');
        Route::post('statements/{id}/send', [StatementController::class, 'send'])->whereUuid('id')->name('agent-commissions.statements.send');

        Route::get('exports/cu', [ExportController::class, 'cu'])->name('agent-commissions.exports.cu');
        Route::get('exports/enasarco', [ExportController::class, 'enasarco'])->name('agent-commissions.exports.enasarco');
    });
