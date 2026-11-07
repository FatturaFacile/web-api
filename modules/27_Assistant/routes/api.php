<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Assistant\Http\Controllers\AssistantController;

Route::prefix('api/v1/assistant')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:assistant', 'throttle:60,1'])
    ->group(function (): void {
        Route::post('ask', [AssistantController::class, 'ask'])->name('assistant.ask');
        Route::get('catalogue', [AssistantController::class, 'catalogue'])->name('assistant.catalogue');
        Route::get('logs', [AssistantController::class, 'logs'])->name('assistant.logs');
    });
