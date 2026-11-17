<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CRM\Http\Controllers\AccountController;
use Modules\CRM\Http\Controllers\ActivityController;
use Modules\CRM\Http\Controllers\CampaignController;
use Modules\CRM\Http\Controllers\ContactInteractionController;
use Modules\CRM\Http\Controllers\ForecastController;
use Modules\CRM\Http\Controllers\LeadActivityEventController;
use Modules\CRM\Http\Controllers\LeadController;
use Modules\CRM\Http\Controllers\LeadDuplicateController;
use Modules\CRM\Http\Controllers\LeadRoutingRuleController;
use Modules\CRM\Http\Controllers\LeadScoringRuleController;
use Modules\CRM\Http\Controllers\LeadSettingController;
use Modules\CRM\Http\Controllers\LeadSourceController;
use Modules\CRM\Http\Controllers\LeadWebhookController;
use Modules\CRM\Http\Controllers\OpportunityController;

// Lead management (scoring, routing, sources, settings, events, duplicates): authenticated and tenant scoped.
// Registered before the legacy group so the static paths win over the "leads/{lead}" resource routes.
Route::prefix('api/v1/crm')->middleware(['api', 'auth:sanctum', 'tenant'])->group(function (): void {
    Route::post('scoring-rules/preview', [LeadScoringRuleController::class, 'preview'])->name('crm.scoring-rules.preview');
    Route::get('scoring-rules', [LeadScoringRuleController::class, 'index'])->name('crm.scoring-rules.index');
    Route::post('scoring-rules', [LeadScoringRuleController::class, 'store'])->name('crm.scoring-rules.store');
    Route::get('scoring-rules/{id}', [LeadScoringRuleController::class, 'show'])->name('crm.scoring-rules.show');
    Route::put('scoring-rules/{id}', [LeadScoringRuleController::class, 'update'])->name('crm.scoring-rules.update');
    Route::delete('scoring-rules/{id}', [LeadScoringRuleController::class, 'destroy'])->name('crm.scoring-rules.destroy');

    Route::get('lead-settings', [LeadSettingController::class, 'show'])->name('crm.lead-settings.show');
    Route::put('lead-settings', [LeadSettingController::class, 'update'])->name('crm.lead-settings.update');

    Route::post('routing-rules/reorder', [LeadRoutingRuleController::class, 'reorder'])->name('crm.routing-rules.reorder');
    Route::get('routing-rules', [LeadRoutingRuleController::class, 'index'])->name('crm.routing-rules.index');
    Route::post('routing-rules', [LeadRoutingRuleController::class, 'store'])->name('crm.routing-rules.store');
    Route::get('routing-rules/{id}', [LeadRoutingRuleController::class, 'show'])->name('crm.routing-rules.show');
    Route::put('routing-rules/{id}', [LeadRoutingRuleController::class, 'update'])->name('crm.routing-rules.update');
    Route::delete('routing-rules/{id}', [LeadRoutingRuleController::class, 'destroy'])->name('crm.routing-rules.destroy');

    Route::get('lead-sources', [LeadSourceController::class, 'index'])->name('crm.lead-sources.index');
    Route::post('lead-sources', [LeadSourceController::class, 'store'])->name('crm.lead-sources.store');
    Route::get('lead-sources/{id}', [LeadSourceController::class, 'show'])->name('crm.lead-sources.show');
    Route::put('lead-sources/{id}', [LeadSourceController::class, 'update'])->name('crm.lead-sources.update');
    Route::delete('lead-sources/{id}', [LeadSourceController::class, 'destroy'])->name('crm.lead-sources.destroy');
    Route::post('lead-sources/{id}/rotate-secret', [LeadSourceController::class, 'rotateSecret'])->name('crm.lead-sources.rotate-secret');

    Route::get('duplicates', [LeadDuplicateController::class, 'index'])->name('crm.duplicates.index');
    Route::post('duplicates/{id}/merge', [LeadDuplicateController::class, 'merge'])->name('crm.duplicates.merge');
    Route::post('duplicates/{id}/dismiss', [LeadDuplicateController::class, 'dismiss'])->name('crm.duplicates.dismiss');

    Route::get('leads/{id}/events', [LeadActivityEventController::class, 'index'])->name('crm.leads.events.index');
    Route::post('leads/{id}/events', [LeadActivityEventController::class, 'store'])->name('crm.leads.events.store');
    Route::post('leads/{id}/rescore', [LeadController::class, 'rescore'])->name('crm.leads.rescore');
    Route::post('leads/{id}/route', [LeadController::class, 'route'])->name('crm.leads.route');

    // Customer registry: interaction history (calls, emails, notes, meetings), shared by web and mobile.
    Route::get('contacts/{id}/interactions', [ContactInteractionController::class, 'index'])->name('crm.contacts.interactions.index');
    Route::post('contacts/{id}/interactions', [ContactInteractionController::class, 'store'])->name('crm.contacts.interactions.store');
});

// Public web-to-lead webhooks: signature / API key verified per source, throttled per source + IP.
Route::prefix('api/v1/webhooks/crm')->middleware(['api', 'throttle:crm-lead-webhook'])->group(function (): void {
    Route::get('leads/{sourceId}', [LeadWebhookController::class, 'verifySubscription'])->name('crm.webhooks.leads.verify');
    Route::post('leads/{sourceId}', [LeadWebhookController::class, 'store'])->name('crm.webhooks.leads.store');
    Route::post('leads/{sourceId}/events', [LeadWebhookController::class, 'events'])->name('crm.webhooks.leads.events');
});

Route::prefix('api/v1/crm')->middleware(['api'])->group(function (): void {
    // 1. Leads
    Route::get('leads/duplicates', [LeadController::class, 'detectDuplicates'])->name('crm.leads.duplicates');
    Route::post('leads/{id}/qualify', [LeadController::class, 'qualify'])->name('crm.leads.qualify');
    Route::post('leads/{id}/disqualify', [LeadController::class, 'disqualify'])->name('crm.leads.disqualify');
    Route::post('leads/{id}/convert', [LeadController::class, 'convert'])->name('crm.leads.convert');
    Route::apiResource('leads', LeadController::class)->names('crm.leads');

    // 2. Opportunities
    Route::post('opportunities/{id}/stage', [OpportunityController::class, 'changeStage'])->name('crm.opportunities.stage');
    Route::post('opportunities/{id}/quote', [OpportunityController::class, 'requestQuote'])->name('crm.opportunities.quote');
    Route::post('opportunities/{id}/project', [OpportunityController::class, 'convertToProject'])->name('crm.opportunities.project');
    Route::apiResource('opportunities', OpportunityController::class)->names('crm.opportunities');

    // 3. Accounts
    Route::get('accounts/{id}/health', [AccountController::class, 'health'])->name('crm.accounts.health');
    Route::apiResource('accounts', AccountController::class)->names('crm.accounts');

    // 4. Activities
    Route::get('activities/timeline', [ActivityController::class, 'timeline'])->name('crm.activities.timeline');
    Route::post('activities/{id}/complete', [ActivityController::class, 'complete'])->name('crm.activities.complete');
    Route::apiResource('activities', ActivityController::class)->names('crm.activities');

    // 5. Forecasts
    Route::post('forecasts/generate', [ForecastController::class, 'generate'])->name('crm.forecasts.generate');
    Route::post('forecasts/{id}/lock', [ForecastController::class, 'lock'])->name('crm.forecasts.lock');
    Route::apiResource('forecasts', ForecastController::class)->only(['index'])->names('crm.forecasts');

    // 6. Campaigns
    Route::get('campaigns/{id}/roi', [CampaignController::class, 'roi'])->name('crm.campaigns.roi');
    Route::apiResource('campaigns', CampaignController::class)->names('crm.campaigns');
});
