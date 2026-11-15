<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Assistant\Http\Controllers\AssistantController;
use Modules\Assistant\Http\Controllers\AssistantThreadController;

Route::prefix('api/v1/assistant')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:assistant', 'throttle:60,1'])
    ->group(function (): void {
        Route::post('ask', [AssistantController::class, 'ask'])->name('assistant.ask');
        Route::get('catalogue', [AssistantController::class, 'catalogue'])->name('assistant.catalogue');
        Route::get('logs', [AssistantController::class, 'logs'])->name('assistant.logs');
        Route::post('logs/{id}/feedback', [AssistantThreadController::class, 'feedback'])->whereUuid('id')->name('assistant.logs.feedback');
        Route::get('threads', [AssistantThreadController::class, 'index'])->name('assistant.threads.index');
        Route::get('threads/{id}', [AssistantThreadController::class, 'show'])->whereUuid('id')->name('assistant.threads.show');
        Route::delete('threads/{id}', [AssistantThreadController::class, 'destroy'])->whereUuid('id')->name('assistant.threads.destroy');
    });
