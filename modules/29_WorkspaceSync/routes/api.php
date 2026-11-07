<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\WorkspaceSync\Http\Controllers\WorkspaceConnectionController;

Route::prefix('api/v1/workspace')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:workspace'])
    ->group(function (): void {
        Route::get('connections', [WorkspaceConnectionController::class, 'index'])->name('workspace.connections.index');
        Route::post('connections/{provider}/authorize', [WorkspaceConnectionController::class, 'authorizeConnection'])->whereIn('provider', ['google', 'microsoft'])->name('workspace.connections.authorize');
        Route::post('connections/{provider}/callback', [WorkspaceConnectionController::class, 'callback'])->whereIn('provider', ['google', 'microsoft'])->name('workspace.connections.callback');
        Route::post('connections/{id}/sync', [WorkspaceConnectionController::class, 'sync'])->whereUuid('id')->name('workspace.connections.sync');
        Route::delete('connections/{id}', [WorkspaceConnectionController::class, 'revoke'])->whereUuid('id')->name('workspace.connections.revoke');
        Route::get('items', [WorkspaceConnectionController::class, 'items'])->name('workspace.items');
    });
