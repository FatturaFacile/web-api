<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CRM\Http\Controllers\AccountController;
use Modules\CRM\Http\Controllers\ActivityController;
use Modules\CRM\Http\Controllers\CampaignController;
use Modules\CRM\Http\Controllers\ForecastController;
use Modules\CRM\Http\Controllers\LeadController;
use Modules\CRM\Http\Controllers\OpportunityController;

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
