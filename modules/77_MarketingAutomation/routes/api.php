<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\MarketingAutomation\Http\Controllers\CampaignController;
use Modules\MarketingAutomation\Http\Controllers\ConsentController;
use Modules\MarketingAutomation\Http\Controllers\MarketingHubController;
use Modules\MarketingAutomation\Http\Controllers\SegmentController;
use Modules\MarketingAutomation\Http\Controllers\TrackingController;

/*
|--------------------------------------------------------------------------
| Marketing Automation, Newsletter DEM, RFM Segmentation & ROI (Mod 155 / Issue #401)
|--------------------------------------------------------------------------
| CH (LCSl/UWG Art. 3 lit. o, nLPD) & IT (GDPR, Art. 130 D.Lgs. 196/2003).
*/
Route::prefix('api/v1/marketing-automation')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])
    ->group(function (): void {
        Route::middleware('module.access:marketing_automation,read')->group(function (): void {
            Route::get('dashboard', [MarketingHubController::class, 'dashboard'])->name('marketing-automation.dashboard');
            Route::get('blueprints', [MarketingHubController::class, 'blueprints'])->name('marketing-automation.blueprints');
            Route::get('catalog/products', [MarketingHubController::class, 'catalogProducts'])->name('marketing-automation.catalog.products');
            Route::get('compliance/strategies', [MarketingHubController::class, 'strategies'])->name('marketing-automation.compliance.strategies');

            Route::get('segments', [SegmentController::class, 'index'])->name('marketing-automation.segments.index');
            Route::get('segments/{id}', [SegmentController::class, 'show'])->whereUuid('id')->name('marketing-automation.segments.show');
            Route::get('segments/{id}/rfm-matrix', [SegmentController::class, 'rfmMatrix'])->whereUuid('id')->name('marketing-automation.segments.rfm-matrix');

            Route::get('campaigns', [CampaignController::class, 'index'])->name('marketing-automation.campaigns.index');
            Route::get('campaigns/{id}', [CampaignController::class, 'show'])->whereUuid('id')->name('marketing-automation.campaigns.show');
            Route::get('campaigns/{id}/logs', [CampaignController::class, 'logs'])->whereUuid('id')->name('marketing-automation.campaigns.logs');
            Route::get('campaigns/{id}/ab-stats', [CampaignController::class, 'abStats'])->whereUuid('id')->name('marketing-automation.campaigns.ab-stats');

            Route::get('consents', [ConsentController::class, 'index'])->name('marketing-automation.consents.index');
            Route::get('consents/{contactId}', [ConsentController::class, 'show'])->whereUuid('contactId')->name('marketing-automation.consents.show');
            Route::get('consent-events', [ConsentController::class, 'events'])->name('marketing-automation.consent-events');
        });

        Route::middleware('module.access:marketing_automation')->group(function (): void {
            Route::post('segments', [SegmentController::class, 'store'])->name('marketing-automation.segments.store');
            Route::post('segments/preview', [SegmentController::class, 'preview'])->name('marketing-automation.segments.preview');
            Route::put('segments/{id}', [SegmentController::class, 'update'])->whereUuid('id')->name('marketing-automation.segments.update');
            Route::delete('segments/{id}', [SegmentController::class, 'destroy'])->whereUuid('id')->name('marketing-automation.segments.destroy');
            Route::post('segments/{id}/evaluate', [SegmentController::class, 'evaluate'])->whereUuid('id')->name('marketing-automation.segments.evaluate');

            Route::post('campaigns', [CampaignController::class, 'store'])->name('marketing-automation.campaigns.store');
            Route::put('campaigns/{id}', [CampaignController::class, 'update'])->whereUuid('id')->name('marketing-automation.campaigns.update');
            Route::delete('campaigns/{id}', [CampaignController::class, 'destroy'])->whereUuid('id')->name('marketing-automation.campaigns.destroy');
            Route::put('campaigns/{id}/journey', [CampaignController::class, 'saveJourney'])->whereUuid('id')->name('marketing-automation.campaigns.journey');
            Route::post('campaigns/{id}/activate', [CampaignController::class, 'activate'])->whereUuid('id')->name('marketing-automation.campaigns.activate');
            Route::post('campaigns/{id}/schedule', [CampaignController::class, 'schedule'])->whereUuid('id')->name('marketing-automation.campaigns.schedule');
            Route::post('campaigns/{id}/pause', [CampaignController::class, 'pause'])->whereUuid('id')->name('marketing-automation.campaigns.pause');
            Route::post('campaigns/{id}/archive', [CampaignController::class, 'archive'])->whereUuid('id')->name('marketing-automation.campaigns.archive');
            Route::post('template-preview', [CampaignController::class, 'previewTemplate'])->name('marketing-automation.template-preview');

            Route::put('consents/{contactId}', [ConsentController::class, 'update'])->whereUuid('contactId')->name('marketing-automation.consents.update');
        });
    });

// Public endpoints: authenticated by the unguessable message token (tracking, unsubscribe) or a shared secret (webhooks).
Route::prefix('api/v1/marketing')
    ->middleware(['api', 'throttle:240,1'])
    ->group(function (): void {
        Route::get('t/o/{token}.gif', [TrackingController::class, 'open'])->where('token', '[A-Za-z0-9]{40}')->name('marketing.track.open');
        Route::get('t/c/{token}', [TrackingController::class, 'click'])->where('token', '[A-Za-z0-9]{40}')->name('marketing.track.click');
        Route::get('u/{token}', [TrackingController::class, 'unsubscribe'])->where('token', '[A-Za-z0-9]{40}')->name('marketing.unsubscribe');
        Route::post('u/{token}', [TrackingController::class, 'unsubscribeOneClick'])->where('token', '[A-Za-z0-9]{40}')->name('marketing.unsubscribe.one-click');
        Route::post('webhooks/{provider}', [TrackingController::class, 'webhook'])->where('provider', 'smtp|ses|sendgrid|mailgun|infomaniak')->name('marketing.webhooks');
    });
