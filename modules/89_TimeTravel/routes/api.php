<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\TimeTravel\Http\Controllers\TimeTravelController;

/*
|--------------------------------------------------------------------------
| Bi-temporal event sourcing and time-travel audit engine (Mod 354 / Issue #600)
|--------------------------------------------------------------------------
| The books at (valid_at, known_at): trial balance, account ledger, aging, inventory and fixed assets; the diff of a cut-off between two
| moments of knowledge; the what-if valuation replay; the Merkle integrity certificate (GeBüV / Art. 957a CO).
*/
Route::prefix('api/v1/time-travel')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:time_travel'])
    ->name('time-travel.')
    ->group(function (): void {
        Route::get('state', [TimeTravelController::class, 'state'])->name('state');
        Route::get('cut-off-diff', [TimeTravelController::class, 'cutOffDiff'])->name('cut-off-diff');
        Route::post('what-if-replay', [TimeTravelController::class, 'whatIfReplay'])->name('what-if-replay');
        Route::get('audit-integrity', [TimeTravelController::class, 'auditIntegrity'])->name('audit-integrity');
        Route::post('checkpoints/generate', [TimeTravelController::class, 'generateCheckpoints'])->name('checkpoints.generate');
    });
