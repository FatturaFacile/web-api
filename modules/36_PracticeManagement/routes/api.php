<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\PracticeManagement\Http\Controllers\ConflictController;
use Modules\PracticeManagement\Http\Controllers\ExpenseController;
use Modules\PracticeManagement\Http\Controllers\FeeProposalController;
use Modules\PracticeManagement\Http\Controllers\PracticeController;
use Modules\PracticeManagement\Http\Controllers\RetainerController;
use Modules\PracticeManagement\Http\Controllers\TrustLedgerController;

Route::prefix('api/v1/practice')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:practice'])
    ->name('api.v1.practice.')
    ->where(['id' => '[0-9a-fA-F-]{36}'])
    ->group(function (): void {
        Route::get('/activity-control', [PracticeController::class, 'activityControl'])->name('activity-control');
        Route::post('/fee-notes/from-activities', [PracticeController::class, 'feeNoteFromActivities'])->name('fee-notes.from-activities');
        Route::get('/fee-notes/{id}/activities', [PracticeController::class, 'feeNoteActivities'])->name('fee-notes.activities');
        Route::get('/closing-overview', [PracticeController::class, 'closingOverview'])->name('closing-overview');
        Route::post('/closings', [PracticeController::class, 'openClosing'])->name('closings.store');
        Route::put('/closings/{id}/checklist', [PracticeController::class, 'checklist'])->name('closings.checklist');
        Route::post('/closings/{id}/close', [PracticeController::class, 'close'])->name('closings.close');
        Route::post('/closings/{id}/reopen', [PracticeController::class, 'reopen'])->name('closings.reopen');

        Route::get('/retainers', [RetainerController::class, 'index'])->name('retainers.index');
        Route::get('/retainers/balances', [RetainerController::class, 'balances'])->name('retainers.balances');
        Route::post('/retainers', [RetainerController::class, 'store'])->name('retainers.store');
        Route::post('/retainers/{id}/apply', [RetainerController::class, 'apply'])->name('retainers.apply');
        Route::post('/retainers/{id}/void', [RetainerController::class, 'void'])->name('retainers.void');
        Route::get('/fee-notes/{id}/retainers', [RetainerController::class, 'feeNote'])->name('fee-notes.retainers');

        Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::put('/expenses/{id}', [ExpenseController::class, 'update'])->name('expenses.update');
        Route::delete('/expenses/{id}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
        Route::post('/expenses/{id}/receipt', [ExpenseController::class, 'storeReceipt'])->name('expenses.receipt.store');
        Route::get('/expenses/{id}/receipt', [ExpenseController::class, 'showReceipt'])->name('expenses.receipt.show');
        Route::delete('/expenses/{id}/receipt', [ExpenseController::class, 'destroyReceipt'])->name('expenses.receipt.destroy');

        Route::get('/trust-ledger', [TrustLedgerController::class, 'index'])->name('trust.index');
        Route::get('/trust-ledger/balances', [TrustLedgerController::class, 'balances'])->name('trust.balances');
        Route::post('/trust-ledger', [TrustLedgerController::class, 'store'])->name('trust.store');
        Route::post('/trust-ledger/{id}/reverse', [TrustLedgerController::class, 'reverse'])->name('trust.reverse');
        Route::post('/trust-ledger/{id}/post', [TrustLedgerController::class, 'post'])->name('trust.post');
        Route::post('/trust-ledger/{id}/reconcile', [TrustLedgerController::class, 'reconcile'])->name('trust.reconcile');

        Route::get('/conflict-checks', [ConflictController::class, 'index'])->name('conflicts.index');
        Route::post('/conflict-checks', [ConflictController::class, 'check'])->name('conflicts.check');
        Route::get('/case-parties', [ConflictController::class, 'parties'])->name('conflict-parties.index');
        Route::post('/case-parties', [ConflictController::class, 'storeParty'])->name('conflict-parties.store');
        Route::delete('/case-parties/{id}', [ConflictController::class, 'destroyParty'])->name('conflict-parties.destroy');

        Route::get('/fee-proposals', [FeeProposalController::class, 'index'])->name('proposals.index');
        Route::post('/fee-proposals', [FeeProposalController::class, 'store'])->name('proposals.store');
        Route::get('/fee-proposals/{id}', [FeeProposalController::class, 'show'])->name('proposals.show');
        Route::put('/fee-proposals/{id}', [FeeProposalController::class, 'update'])->name('proposals.update');
        Route::get('/fee-proposals/{id}/pdf', [FeeProposalController::class, 'pdf'])->name('proposals.pdf');
        Route::post('/fee-proposals/expire', [FeeProposalController::class, 'expire'])->name('proposals.expire');
        Route::post('/fee-proposals/{id}/send', [FeeProposalController::class, 'send'])->name('proposals.send');
        Route::post('/fee-proposals/{id}/accept', [FeeProposalController::class, 'accept'])->name('proposals.accept');
        Route::post('/fee-proposals/{id}/decline', [FeeProposalController::class, 'decline'])->name('proposals.decline');
    });
