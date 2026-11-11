<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\GeneralLedger\Http\Controllers\ConsolidationGroupController;
use Modules\GeneralLedger\Http\Controllers\ConsolidationInputController;
use Modules\GeneralLedger\Http\Controllers\ConsolidationRunController;
use Modules\GeneralLedger\Http\Middleware\RejectApiKeyAccess;

/*
| Accounting consolidation of the entities of a group. Same access rule as the rest of the ledger (module.access:ledger on
| the active tenant, which owns the group), plus the check on every member entity inside the services, plus no API keys.
*/
Route::prefix('api/v1/general-ledger/consolidation')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', RejectApiKeyAccess::class, 'module.access:ledger'])
    ->group(function (): void {
        Route::get('groups', [ConsolidationGroupController::class, 'index']);
        Route::post('groups', [ConsolidationGroupController::class, 'store']);
        Route::get('groups/{id}', [ConsolidationGroupController::class, 'show'])->whereUuid('id');
        Route::put('groups/{id}', [ConsolidationGroupController::class, 'update'])->whereUuid('id');
        Route::delete('groups/{id}', [ConsolidationGroupController::class, 'destroy'])->whereUuid('id');
        Route::post('groups/{id}/members', [ConsolidationGroupController::class, 'addMember'])->whereUuid('id');
        Route::put('groups/{id}/members/{memberId}', [ConsolidationGroupController::class, 'updateMember'])->whereUuid(['id', 'memberId']);
        Route::delete('groups/{id}/members/{memberId}', [ConsolidationGroupController::class, 'removeMember'])->whereUuid(['id', 'memberId']);
        Route::post('groups/{id}/relations', [ConsolidationGroupController::class, 'storeRelation'])->whereUuid('id');
        Route::delete('relations/{relationId}', [ConsolidationGroupController::class, 'destroyRelation'])->whereUuid('relationId');
        Route::post('relations/{relationId}/mappings', [ConsolidationGroupController::class, 'storeMapping'])->whereUuid('relationId');
        Route::delete('mappings/{mappingId}', [ConsolidationGroupController::class, 'destroyMapping'])->whereUuid('mappingId');

        Route::get('groups/{id}/account-mappings', [ConsolidationInputController::class, 'indexAccountMappings'])->whereUuid('id');
        Route::post('groups/{id}/account-mappings', [ConsolidationInputController::class, 'storeAccountMapping'])->whereUuid('id');
        Route::delete('account-mappings/{mappingId}', [ConsolidationInputController::class, 'destroyAccountMapping'])->whereUuid('mappingId');
        Route::get('groups/{id}/acquisitions', [ConsolidationInputController::class, 'indexAcquisitions'])->whereUuid('id');
        Route::post('groups/{id}/acquisitions', [ConsolidationInputController::class, 'storeAcquisition'])->whereUuid('id');
        Route::post('acquisitions/{acquisitionId}/impairments', [ConsolidationInputController::class, 'storeImpairment'])->whereUuid('acquisitionId');
        Route::delete('acquisitions/{acquisitionId}', [ConsolidationInputController::class, 'destroyAcquisition'])->whereUuid('acquisitionId');
        Route::get('groups/{id}/intragroup-transactions', [ConsolidationInputController::class, 'indexTransactions'])->whereUuid('id');
        Route::post('groups/{id}/intragroup-transactions', [ConsolidationInputController::class, 'storeTransaction'])->whereUuid('id');
        Route::delete('intragroup-transactions/{transactionId}', [ConsolidationInputController::class, 'destroyTransaction'])->whereUuid('transactionId');
        Route::get('groups/{id}/intragroup-dividends', [ConsolidationInputController::class, 'indexDividends'])->whereUuid('id');
        Route::post('groups/{id}/intragroup-dividends', [ConsolidationInputController::class, 'storeDividend'])->whereUuid('id');
        Route::delete('intragroup-dividends/{dividendId}', [ConsolidationInputController::class, 'destroyDividend'])->whereUuid('dividendId');

        Route::get('groups/{id}/intercompany-reconciliation', [ConsolidationRunController::class, 'reconciliation'])->whereUuid('id');
        Route::get('groups/{id}/runs', [ConsolidationRunController::class, 'index'])->whereUuid('id');
        Route::post('groups/{id}/runs', [ConsolidationRunController::class, 'store'])->whereUuid('id');
        Route::get('runs/{runId}', [ConsolidationRunController::class, 'show'])->whereUuid('runId');
        Route::get('runs/{runId}/verify', [ConsolidationRunController::class, 'verify'])->whereUuid('runId');
        Route::post('runs/{runId}/lock', [ConsolidationRunController::class, 'lock'])->whereUuid('runId');
        Route::delete('runs/{runId}', [ConsolidationRunController::class, 'destroy'])->whereUuid('runId');
        Route::get('runs/{runId}/trial-balance', [ConsolidationRunController::class, 'trialBalance'])->whereUuid('runId');
        Route::get('runs/{runId}/balance-sheet', [ConsolidationRunController::class, 'balanceSheet'])->whereUuid('runId');
        Route::get('runs/{runId}/income-statement', [ConsolidationRunController::class, 'incomeStatement'])->whereUuid('runId');
        Route::get('runs/{runId}/reclassified', [ConsolidationRunController::class, 'reclassified'])->whereUuid('runId');
    });
