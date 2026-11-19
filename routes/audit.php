<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuditLogController;
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

Route::middleware('module.access:audit_trail')->prefix('audit-logs')->name('audit-logs.')->group(function (): void {
    Route::get('/', [AuditLogController::class, 'index'])->middleware('audit.permission:audit.view')->name('index');
    Route::get('/export', [AuditLogController::class, 'export'])->middleware(['audit.permission:audit.export', 'throttle:10,1'])->name('export');
    Route::post('/verify-integrity', [AuditLogController::class, 'verify'])->middleware(['audit.permission:audit.verify', 'throttle:20,1'])->name('verify');
    Route::get('/worm-batches', [AuditLogController::class, 'wormBatches'])->middleware('audit.permission:audit.view')->name('worm-batches.index');
    Route::post('/worm-batches/seal', [AuditLogController::class, 'seal'])->middleware(['audit.permission:audit.worm.manage', 'throttle:10,1'])->name('worm-batches.seal');
    Route::get('/entity/{type}/{id}', [AuditLogController::class, 'entity'])->middleware('audit.permission:audit.view')->name('entity');
    Route::get('/{id}', [AuditLogController::class, 'show'])->middleware('audit.permission:audit.view')->whereUuid('id')->name('show');
});
