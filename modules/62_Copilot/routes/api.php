<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Copilot\Http\Controllers\CopilotActionController;
use Modules\Copilot\Http\Controllers\CopilotAdminController;
use Modules\Copilot\Http\Controllers\CopilotConversationController;
use Modules\Copilot\Http\Controllers\CopilotMessageController;

Route::prefix('api/v1/copilot')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:copilot', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('conversations', [CopilotConversationController::class, 'index'])->name('copilot.conversations.index');
        Route::post('conversations', [CopilotConversationController::class, 'store'])->name('copilot.conversations.store');
        Route::get('conversations/{id}', [CopilotConversationController::class, 'show'])->whereUuid('id')->name('copilot.conversations.show');
        Route::delete('conversations/{id}', [CopilotConversationController::class, 'destroy'])->whereUuid('id')->name('copilot.conversations.destroy');
        Route::post('conversations/{id}/messages', [CopilotMessageController::class, 'store'])->whereUuid('id')->name('copilot.messages.store');
        Route::post('actions/{messageId}/confirm', [CopilotActionController::class, 'confirm'])->whereUuid('messageId')->name('copilot.actions.confirm');
        Route::post('actions/{messageId}/reject', [CopilotActionController::class, 'reject'])->whereUuid('messageId')->name('copilot.actions.reject');
        Route::get('audit-logs', [CopilotAdminController::class, 'auditLogs'])->name('copilot.audit-logs');
        Route::post('knowledge', [CopilotAdminController::class, 'indexKnowledge'])->name('copilot.knowledge.index');
    });
