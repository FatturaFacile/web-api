<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CustomerSatisfaction\Http\Controllers\AnalyticsController;
use Modules\CustomerSatisfaction\Http\Controllers\DispatchController;
use Modules\CustomerSatisfaction\Http\Controllers\LookupController;
use Modules\CustomerSatisfaction\Http\Controllers\PublicSurveyController;
use Modules\CustomerSatisfaction\Http\Controllers\RecoveryCaseController;
use Modules\CustomerSatisfaction\Http\Controllers\ReportController;
use Modules\CustomerSatisfaction\Http\Controllers\ResponseController;
use Modules\CustomerSatisfaction\Http\Controllers\TemplateController;

/*
|--------------------------------------------------------------------------
| Customer satisfaction: NPS / CSAT / CES surveys, closed-loop detractor recovery and ISO 9001 § 9.1.2 (Mod 245 / Issue #491)
|--------------------------------------------------------------------------
| Staff routes (templates and trigger rules, dispatch monitor, answers, recovery queue, analytics, Management Review reports) and the
| public token-secured routes of the one-click score capture and the mobile landing page (no login).
*/
Route::prefix('api/v1/customer-satisfaction')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:customer_satisfaction'])
    ->group(function (): void {
        Route::get('lookups', [LookupController::class, 'lookups'])->name('customer-satisfaction.lookups');

        Route::get('templates', [TemplateController::class, 'index'])->name('customer-satisfaction.templates.index');
        Route::post('templates', [TemplateController::class, 'store'])->name('customer-satisfaction.templates.store');
        Route::post('templates/install-defaults', [TemplateController::class, 'installDefaults'])->name('customer-satisfaction.templates.install-defaults');
        Route::get('templates/{id}', [TemplateController::class, 'show'])->whereUuid('id')->name('customer-satisfaction.templates.show');
        Route::put('templates/{id}', [TemplateController::class, 'update'])->whereUuid('id')->name('customer-satisfaction.templates.update');
        Route::delete('templates/{id}', [TemplateController::class, 'destroy'])->whereUuid('id')->name('customer-satisfaction.templates.destroy');

        Route::post('triggers', [DispatchController::class, 'trigger'])->name('customer-satisfaction.triggers.store');
        Route::get('dispatches', [DispatchController::class, 'index'])->name('customer-satisfaction.dispatches.index');
        Route::post('dispatches/process', [DispatchController::class, 'process'])->name('customer-satisfaction.dispatches.process');
        Route::get('dispatches/{id}', [DispatchController::class, 'show'])->whereUuid('id')->name('customer-satisfaction.dispatches.show');

        Route::get('responses', [ResponseController::class, 'index'])->name('customer-satisfaction.responses.index');
        Route::get('responses/{id}', [ResponseController::class, 'show'])->whereUuid('id')->name('customer-satisfaction.responses.show');
        Route::get('responses/{id}/voice', [ResponseController::class, 'voice'])->whereUuid('id')->name('customer-satisfaction.responses.voice');

        Route::get('recovery-cases', [RecoveryCaseController::class, 'index'])->name('customer-satisfaction.recovery-cases.index');
        Route::get('recovery-cases/{id}', [RecoveryCaseController::class, 'show'])->whereUuid('id')->name('customer-satisfaction.recovery-cases.show');
        Route::post('recovery-cases/{id}/transition', [RecoveryCaseController::class, 'transition'])->whereUuid('id')->name('customer-satisfaction.recovery-cases.transition');
        Route::put('recovery-cases/{id}/assign', [RecoveryCaseController::class, 'assign'])->whereUuid('id')->name('customer-satisfaction.recovery-cases.assign');

        Route::get('analytics/overview', [AnalyticsController::class, 'overview'])->name('customer-satisfaction.analytics.overview');
        Route::get('analytics/breakdown', [AnalyticsController::class, 'breakdown'])->name('customer-satisfaction.analytics.breakdown');
        Route::get('analytics/trend', [AnalyticsController::class, 'trend'])->name('customer-satisfaction.analytics.trend');
        Route::get('analytics/sentiment', [AnalyticsController::class, 'sentiment'])->name('customer-satisfaction.analytics.sentiment');

        Route::get('reports', [ReportController::class, 'index'])->name('customer-satisfaction.reports.index');
        Route::post('reports', [ReportController::class, 'store'])->name('customer-satisfaction.reports.store');
        Route::get('reports/{id}', [ReportController::class, 'show'])->whereUuid('id')->name('customer-satisfaction.reports.show');
        Route::get('reports/{id}/export', [ReportController::class, 'export'])->whereUuid('id')->name('customer-satisfaction.reports.export');
    });

// Public: the survey token is the credential; no session, no tenant header. Throttled against token guessing.
Route::prefix('api/v1/public/surveys')
    ->middleware(['api', 'throttle:60,1', 'locale'])
    ->group(function (): void {
        Route::get('{token}', [PublicSurveyController::class, 'show'])->name('customer-satisfaction.public.show');
        Route::get('{token}/rate/{score}', [PublicSurveyController::class, 'rate'])->whereNumber('score')->name('customer-satisfaction.public.rate');
        Route::post('{token}/respond', [PublicSurveyController::class, 'respond'])->name('customer-satisfaction.public.respond');
        Route::post('{token}/whatsapp-reply', [PublicSurveyController::class, 'whatsappReply'])->name('customer-satisfaction.public.whatsapp-reply');
        Route::post('{token}/feedback', [PublicSurveyController::class, 'feedback'])->name('customer-satisfaction.public.feedback');
        Route::match(['get', 'post'], '{token}/opt-out', [PublicSurveyController::class, 'optOut'])->name('customer-satisfaction.public.opt-out');
    });
