<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CRM\Http\Controllers\AccountController;
use Modules\CRM\Http\Controllers\ActivityController;
use Modules\CRM\Http\Controllers\CampaignController;
use Modules\CRM\Http\Controllers\CampaignMemberController;
use Modules\CRM\Http\Controllers\ConsentController;
use Modules\CRM\Http\Controllers\CrmReportController;
use Modules\CRM\Http\Controllers\DuplicateController;
use Modules\CRM\Http\Controllers\ForecastController;
use Modules\CRM\Http\Controllers\LeadController;
use Modules\CRM\Http\Controllers\LeadScoringController;
use Modules\CRM\Http\Controllers\OpportunityController;
use Modules\CRM\Http\Controllers\PipelineStageController;
use Modules\CRM\Http\Controllers\SegmentController;

// module.access:crm gives reads to every member and writes (any non-GET verb) only to the CRM write roles.
Route::prefix('api/v1/crm')->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:crm'])->group(function (): void {
    // 1. Leads
    Route::get('leads/duplicates', [LeadController::class, 'detectDuplicates'])->middleware('deprecated:/api/v1/crm/duplicates?entity=leads')->name('crm.leads.duplicates');
    Route::get('leads/{id}/score', [LeadScoringController::class, 'explain'])->name('crm.leads.score');
    Route::post('leads/{id}/qualify', [LeadController::class, 'qualify'])->name('crm.leads.qualify');
    Route::post('leads/{id}/disqualify', [LeadController::class, 'disqualify'])->name('crm.leads.disqualify');
    Route::post('leads/{id}/convert', [LeadController::class, 'convert'])->name('crm.leads.convert');
    Route::apiResource('leads', LeadController::class)->names('crm.leads');

    // 1b. Lead scoring model
    Route::post('lead-scoring-rules/recalculate', [LeadScoringController::class, 'recalculate'])->name('crm.lead-scoring-rules.recalculate');
    Route::apiResource('lead-scoring-rules', LeadScoringController::class)->except(['show'])->parameters(['lead-scoring-rules' => 'id'])->names('crm.lead-scoring-rules');

    // 2. Opportunities and pipeline configuration
    Route::post('opportunities/{id}/stage', [OpportunityController::class, 'changeStage'])->name('crm.opportunities.stage');
    Route::post('opportunities/{id}/quote', [OpportunityController::class, 'requestQuote'])->name('crm.opportunities.quote');
    Route::post('opportunities/{id}/project', [OpportunityController::class, 'convertToProject'])->name('crm.opportunities.project');
    Route::apiResource('opportunities', OpportunityController::class)->except(['update'])->names('crm.opportunities');
    Route::post('pipeline-stages/defaults', [PipelineStageController::class, 'installDefaults'])->name('crm.pipeline-stages.defaults');
    Route::apiResource('pipeline-stages', PipelineStageController::class)->except(['show'])->parameters(['pipeline-stages' => 'id'])->names('crm.pipeline-stages');

    // 3. Accounts (linked to the contact master)
    Route::get('accounts/party-links', [AccountController::class, 'partyLinks'])->name('crm.accounts.party-links');
    Route::get('accounts/{id}/health', [AccountController::class, 'health'])->name('crm.accounts.health');
    Route::apiResource('accounts', AccountController::class)->names('crm.accounts');

    // 3b. Duplicate detection and audited contact merge
    Route::get('duplicates', [DuplicateController::class, 'index'])->name('crm.duplicates.index');
    Route::get('contact-merges', [DuplicateController::class, 'merges'])->name('crm.contact-merges.index');
    Route::post('contact-merges', [DuplicateController::class, 'merge'])->name('crm.contact-merges.store');

    // 4. Activities
    Route::get('activities/timeline', [ActivityController::class, 'timeline'])->name('crm.activities.timeline');
    Route::post('activities/{id}/complete', [ActivityController::class, 'complete'])->name('crm.activities.complete');
    Route::apiResource('activities', ActivityController::class)->except(['destroy'])->names('crm.activities');

    // 5. Forecasts
    Route::post('forecasts/generate', [ForecastController::class, 'generate'])->name('crm.forecasts.generate');
    Route::post('forecasts/{id}/lock', [ForecastController::class, 'lock'])->name('crm.forecasts.lock');
    Route::apiResource('forecasts', ForecastController::class)->only(['index'])->names('crm.forecasts');

    // 6. Campaigns, audience and consent-aware delivery
    Route::get('campaigns/{id}/roi', [CampaignController::class, 'roi'])->name('crm.campaigns.roi');
    Route::get('campaigns/{id}/members', [CampaignMemberController::class, 'index'])->name('crm.campaigns.members.index');
    Route::post('campaigns/{id}/members', [CampaignMemberController::class, 'store'])->name('crm.campaigns.members.store');
    Route::delete('campaigns/{id}/members/{memberId}', [CampaignMemberController::class, 'destroy'])->name('crm.campaigns.members.destroy');
    Route::post('campaigns/{id}/send', [CampaignMemberController::class, 'send'])->name('crm.campaigns.send');
    Route::apiResource('campaigns', CampaignController::class)->except(['update', 'destroy'])->names('crm.campaigns');

    // 7. Marketing segments and GDPR consents
    Route::get('segments/{id}/members', [SegmentController::class, 'members'])->name('crm.segments.members');
    Route::apiResource('segments', SegmentController::class)->parameters(['segments' => 'id'])->names('crm.segments');
    Route::get('consents', [ConsentController::class, 'index'])->name('crm.consents.index');
    Route::post('consents', [ConsentController::class, 'store'])->name('crm.consents.store');

    // 8. Reports
    Route::get('reports/pipeline', [CrmReportController::class, 'pipeline'])->name('crm.reports.pipeline');
    Route::get('reports/conversion', [CrmReportController::class, 'conversion'])->name('crm.reports.conversion');
    Route::get('reports/activities', [CrmReportController::class, 'activities'])->name('crm.reports.activities');
});
