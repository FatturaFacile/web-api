<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Audit\AuditLedgerController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\OfflineAuditBatchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Immutable audit trail
|--------------------------------------------------------------------------
|
| Included by routes/api.php inside the authenticated tenant group. Read, verify, export and WORM archive of the audit
| trail. There is no route that edits or deletes a record, by design. The literal paths come before {id}.
|
*/

// Offline audit queue of the desktop and mobile clients (any authenticated member of the tenant: it is the user's own device).
Route::middleware('module.access:audit_trail')->post('audit/offline-batches', [OfflineAuditBatchController::class, 'store'])
    ->middleware('throttle:60,1')->name('audit.offline-batches.store');

Route::middleware('module.access:audit_trail')->prefix('audit-logs')->name('audit-logs.')->group(function (): void {
    Route::get('/', [AuditLogController::class, 'index'])->middleware('audit.permission:audit.view')->name('index');
    Route::get('/export', [AuditLogController::class, 'export'])->middleware(['audit.permission:audit.export', 'throttle:10,1'])->name('export');
    Route::post('/verify-integrity', [AuditLogController::class, 'verify'])->middleware(['audit.permission:audit.verify', 'throttle:20,1'])->name('verify');
    Route::get('/worm-batches', [AuditLogController::class, 'wormBatches'])->middleware('audit.permission:audit.view')->name('worm-batches.index');
    Route::post('/worm-batches/seal', [AuditLogController::class, 'seal'])->middleware(['audit.permission:audit.worm.manage', 'throttle:10,1'])->name('worm-batches.seal');
    Route::get('/entity/{type}/{id}', [AuditLogController::class, 'entity'])->middleware('audit.permission:audit.view')->name('entity');
    Route::get('/{id}', [AuditLogController::class, 'show'])->middleware('audit.permission:audit.view')->whereUuid('id')->name('show');
});

// History of one record (Mod 005 ledger): gated by AuditPolicy::history, i.e. by the right to read that record's module. An entry
// of the ledger can never be edited or deleted: those methods are refused with 405.
Route::get('audit/history/{resourceType}/{resourceId}', [AuditLedgerController::class, 'history'])->name('audit.history');
Route::match(['PUT', 'PATCH', 'DELETE', 'POST'], 'audit/history/{resourceType}/{resourceId}', [AuditLedgerController::class, 'rejectMutation'])->name('audit.history.reject-mutation');
