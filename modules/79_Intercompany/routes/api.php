<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\GeneralLedger\Http\Middleware\RejectApiKeyAccess;
use Modules\Intercompany\Http\Controllers\GroupConfigController;
use Modules\Intercompany\Http\Controllers\NettingController;
use Modules\Intercompany\Http\Controllers\PeriodRateController;
use Modules\Intercompany\Http\Controllers\ReconciliationController;
use Modules\Intercompany\Http\Controllers\RunController;
use Modules\Intercompany\Http\Controllers\TransactionController;

/*
|--------------------------------------------------------------------------
| Intercompany accounting and consolidation workflow (Mod 021)
|--------------------------------------------------------------------------
| Mirror postings between the entities of a consolidation group, payment sync and netting, the reciprocal reconciliation
| cockpit, period rates, the guided consolidation run, statements by standard, cash flow and the consolidated package. The
| active tenant owns the group; the access to every member entity is checked again inside the services, and API keys, which
| are bound to a single tenant, are refused.
*/
Route::prefix('api/v1/intercompany')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', RejectApiKeyAccess::class, 'module.access:intercompany'])
    ->group(function (): void {
        Route::get('lookups', [GroupConfigController::class, 'lookups'])->name('intercompany.lookups');

        Route::get('groups/{groupId}/settings', [GroupConfigController::class, 'showSettings'])->whereUuid('groupId')->name('intercompany.settings.show');
        Route::put('groups/{groupId}/settings', [GroupConfigController::class, 'updateSettings'])->whereUuid('groupId')->name('intercompany.settings.update');
        Route::get('groups/{groupId}/party-links', [GroupConfigController::class, 'partyLinks'])->whereUuid('groupId')->name('intercompany.party-links.index');
        Route::post('groups/{groupId}/party-links', [GroupConfigController::class, 'storePartyLink'])->whereUuid('groupId')->name('intercompany.party-links.store');
        Route::delete('party-links/{id}', [GroupConfigController::class, 'destroyPartyLink'])->whereUuid('id')->name('intercompany.party-links.destroy');
        Route::get('groups/{groupId}/scope-validation', [GroupConfigController::class, 'scopeValidation'])->whereUuid('groupId')->name('intercompany.scope-validation');

        Route::get('groups/{groupId}/transactions', [TransactionController::class, 'index'])->whereUuid('groupId')->name('intercompany.transactions.index');
        Route::post('groups/{groupId}/transactions/scan', [TransactionController::class, 'scan'])->whereUuid('groupId')->name('intercompany.transactions.scan');
        Route::get('transactions/{id}', [TransactionController::class, 'show'])->whereUuid('id')->name('intercompany.transactions.show');
        Route::post('transactions/{id}/mirror', [TransactionController::class, 'mirror'])->whereUuid('id')->name('intercompany.transactions.mirror');
        Route::post('transactions/{id}/confirm', [TransactionController::class, 'confirm'])->whereUuid('id')->name('intercompany.transactions.confirm');
        Route::post('transactions/{id}/reverse', [TransactionController::class, 'reverse'])->whereUuid('id')->name('intercompany.transactions.reverse');
        Route::post('transactions/{id}/sync-payment', [TransactionController::class, 'syncPayment'])->whereUuid('id')->name('intercompany.transactions.sync-payment');

        Route::get('groups/{groupId}/netting', [NettingController::class, 'index'])->whereUuid('groupId')->name('intercompany.netting.index');
        Route::post('groups/{groupId}/netting', [NettingController::class, 'store'])->whereUuid('groupId')->name('intercompany.netting.store');
        Route::get('netting/{id}', [NettingController::class, 'show'])->whereUuid('id')->name('intercompany.netting.show');
        Route::post('netting/{id}/confirm', [NettingController::class, 'confirm'])->whereUuid('id')->name('intercompany.netting.confirm');
        Route::post('netting/{id}/cancel', [NettingController::class, 'cancel'])->whereUuid('id')->name('intercompany.netting.cancel');

        Route::get('groups/{groupId}/reconciliation/batches', [ReconciliationController::class, 'batches'])->whereUuid('groupId')->name('intercompany.reconciliation.batches');
        Route::post('groups/{groupId}/reconciliation/batches', [ReconciliationController::class, 'run'])->whereUuid('groupId')->name('intercompany.reconciliation.run');
        Route::get('groups/{groupId}/reconciliation/matrix', [ReconciliationController::class, 'matrix'])->whereUuid('groupId')->name('intercompany.reconciliation.matrix');
        Route::get('reconciliation/batches/{id}', [ReconciliationController::class, 'show'])->whereUuid('id')->name('intercompany.reconciliation.show');
        Route::post('reconciliation/items/{id}/match', [ReconciliationController::class, 'match'])->whereUuid('id')->name('intercompany.reconciliation.match');
        Route::post('reconciliation/items/{id}/unmatch', [ReconciliationController::class, 'unmatch'])->whereUuid('id')->name('intercompany.reconciliation.unmatch');
        Route::post('reconciliation/items/{id}/dispute', [ReconciliationController::class, 'dispute'])->whereUuid('id')->name('intercompany.reconciliation.dispute');
        Route::post('reconciliation/items/{id}/write-off', [ReconciliationController::class, 'writeOff'])->whereUuid('id')->name('intercompany.reconciliation.write-off');
        Route::post('reconciliation/items/{id}/adjust', [ReconciliationController::class, 'adjust'])->whereUuid('id')->name('intercompany.reconciliation.adjust');

        Route::get('groups/{groupId}/period-rates', [PeriodRateController::class, 'index'])->whereUuid('groupId')->name('intercompany.period-rates.index');
        Route::post('groups/{groupId}/period-rates', [PeriodRateController::class, 'store'])->whereUuid('groupId')->name('intercompany.period-rates.store');
        Route::delete('period-rates/{id}', [PeriodRateController::class, 'destroy'])->whereUuid('id')->name('intercompany.period-rates.destroy');

        Route::get('runs/{runId}/workflow', [RunController::class, 'workflow'])->whereUuid('runId')->name('intercompany.runs.workflow');
        Route::post('runs/{runId}/workflow/advance', [RunController::class, 'advance'])->whereUuid('runId')->name('intercompany.runs.advance');
        Route::post('runs/{runId}/reopen', [RunController::class, 'reopen'])->whereUuid('runId')->name('intercompany.runs.reopen');
        Route::get('runs/{runId}/elimination-ledger', [RunController::class, 'eliminationLedger'])->whereUuid('runId')->name('intercompany.runs.elimination-ledger');
        Route::get('runs/{runId}/nci-schedule', [RunController::class, 'nci'])->whereUuid('runId')->name('intercompany.runs.nci');
        Route::get('runs/{runId}/shareholdings', [RunController::class, 'shareholdings'])->whereUuid('runId')->name('intercompany.runs.shareholdings');
        Route::get('runs/{runId}/statements', [RunController::class, 'statements'])->whereUuid('runId')->name('intercompany.runs.statements');
        Route::get('runs/{runId}/cash-flow', [RunController::class, 'cashFlow'])->whereUuid('runId')->name('intercompany.runs.cash-flow');
        Route::get('runs/{runId}/manual-adjustments', [RunController::class, 'manualAdjustments'])->whereUuid('runId')->name('intercompany.runs.manual-adjustments.index');
        Route::post('runs/{runId}/manual-adjustments', [RunController::class, 'storeManualAdjustment'])->whereUuid('runId')->name('intercompany.runs.manual-adjustments.store');
        Route::delete('manual-adjustments/{id}', [RunController::class, 'voidManualAdjustment'])->whereUuid('id')->name('intercompany.manual-adjustments.void');
        Route::get('runs/{runId}/packages', [RunController::class, 'packages'])->whereUuid('runId')->name('intercompany.runs.packages.index');
        Route::post('runs/{runId}/packages', [RunController::class, 'generatePackage'])->whereUuid('runId')->name('intercompany.runs.packages.store');
        Route::get('packages/{id}', [RunController::class, 'showPackage'])->whereUuid('id')->name('intercompany.packages.show');
        Route::get('packages/{id}/download', [RunController::class, 'downloadPackage'])->whereUuid('id')->name('intercompany.packages.download');
    });
