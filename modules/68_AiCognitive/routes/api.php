<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\AiCognitive\Presentation\Http\Controllers\AiAuditController;
use Modules\AiCognitive\Presentation\Http\Controllers\AiCopilotController;
use Modules\AiCognitive\Presentation\Http\Controllers\AiInsightController;
use Modules\AiCognitive\Presentation\Http\Controllers\AiSettingsController;
use Modules\AiCognitive\Presentation\Http\Controllers\AiSuggestionController;

// ai_copilot (ai.copilot.use): every member asks the copilot and drafts messages. ai_review (ai.suggestions.review): the
// finance and sales teams work the review queue and the insights. ai_admin (ai.admin.configure): owners and admins set up
// providers, policies and read the usage and audit trail (see config/permissions.php).
Route::prefix('api/v1/ai')->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])->group(function (): void {
    Route::middleware(['module.access:ai_copilot', 'throttle:60,1'])->group(function (): void {
        Route::post('copilot/query', [AiCopilotController::class, 'query'])->name('ai.copilot.query');
        Route::post('copilot/drafts', [AiCopilotController::class, 'draft'])->name('ai.copilot.drafts');
    });

    Route::middleware(['module.access:ai_review', 'throttle:60,1'])->group(function (): void {
        Route::get('suggestions', [AiSuggestionController::class, 'index'])->name('ai.suggestions.index');
        Route::post('suggestions/extract', [AiSuggestionController::class, 'extract'])->name('ai.suggestions.extract');
        Route::get('suggestions/{id}', [AiSuggestionController::class, 'show'])->whereUuid('id')->name('ai.suggestions.show');
        Route::post('suggestions/{id}/approve', [AiSuggestionController::class, 'approve'])->whereUuid('id')->name('ai.suggestions.approve');
        Route::post('suggestions/{id}/reject', [AiSuggestionController::class, 'reject'])->whereUuid('id')->name('ai.suggestions.reject');
        Route::post('accounting/coding', [AiSuggestionController::class, 'coding'])->name('ai.accounting.coding');
        Route::post('accounting/reconciliation', [AiSuggestionController::class, 'reconciliation'])->name('ai.accounting.reconciliation');
        Route::get('insights', [AiInsightController::class, 'dashboard'])->name('ai.insights.dashboard');
        Route::post('insights/generate', [AiInsightController::class, 'generate'])->name('ai.insights.generate');
    });

    Route::middleware(['module.access:ai_admin', 'throttle:30,1'])->group(function (): void {
        Route::get('settings', [AiSettingsController::class, 'show'])->name('ai.settings.show');
        Route::put('settings/providers/{provider}', [AiSettingsController::class, 'updateProvider'])->name('ai.settings.providers.update');
        Route::put('settings/rules/{workflow}', [AiSettingsController::class, 'updateRule'])->name('ai.settings.rules.update');
        Route::post('copilot/index/rebuild', [AiCopilotController::class, 'rebuildIndex'])->name('ai.copilot.index.rebuild');
        Route::get('usage-audit', [AiAuditController::class, 'index'])->name('ai.usage-audit');
    });
});
