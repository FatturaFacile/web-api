<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\SalesBoundedContext\Http\Controllers\CpqQuoteAndOrderController;
use Modules\SalesBoundedContext\Http\Controllers\Customer360DossierController;
use Modules\SalesBoundedContext\Http\Controllers\DdtCommissionPortalController;
use Modules\SalesBoundedContext\Http\Controllers\FranchiseSurveyWelfareController;
use Modules\SalesBoundedContext\Http\Controllers\LeadPipelineController;
use Modules\SalesBoundedContext\Http\Controllers\MarketingTenderCpqController;
use Modules\SalesBoundedContext\Http\Controllers\PricingAndSubscriptionController;
use Modules\SalesBoundedContext\Http\Controllers\RebateReturnVanController;
use Modules\SalesBoundedContext\Http\Controllers\SalesBoundedContextConfigController;

/*
|--------------------------------------------------------------------------
| Epic [Dominio 04] SD#139 — Sales Bounded Context (Batch 1: Mod 033-042 & Batch 2: Mod 155-358)
|--------------------------------------------------------------------------
*/
Route::prefix('api/v1/sales-bounded-context')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:sales_bounded_context'])
    ->name('api.v1.sales-bounded-context.')
    ->group(function (): void {
        Route::get('/config', [SalesBoundedContextConfigController::class, 'show'])->name('config');
        Route::get('/overview', [SalesBoundedContextConfigController::class, 'overview'])->name('overview');

        // Mod 033: Customer & Contact 360° Management
        Route::get('/dossiers', [Customer360DossierController::class, 'index'])->name('dossiers.index');
        Route::post('/dossiers', [Customer360DossierController::class, 'store'])->name('dossiers.store');
        Route::get('/dossiers/{id}/summary', [Customer360DossierController::class, 'summary'])->whereUuid('id')->name('dossiers.summary');

        // Mod 034 & 035: Lead Management & Scoring + Opportunity Pipeline & Forecasting
        Route::get('/leads', [LeadPipelineController::class, 'leadsIndex'])->name('leads.index');
        Route::post('/leads', [LeadPipelineController::class, 'leadsStore'])->name('leads.store');
        Route::get('/opportunities', [LeadPipelineController::class, 'opportunitiesIndex'])->name('opportunities.index');
        Route::post('/opportunities', [LeadPipelineController::class, 'opportunitiesStore'])->name('opportunities.store');

        // Mod 036 & 037: Interactive CPQ Quotes + Sales Orders & Framework Agreements
        Route::get('/quotes', [CpqQuoteAndOrderController::class, 'quotesIndex'])->name('quotes.index');
        Route::post('/quotes', [CpqQuoteAndOrderController::class, 'quotesStore'])->name('quotes.store');
        Route::post('/quotes/{id}/sign', [CpqQuoteAndOrderController::class, 'quotesSign'])->whereUuid('id')->name('quotes.sign');
        Route::post('/quotes/{id}/convert-to-order', [CpqQuoteAndOrderController::class, 'quotesConvertToOrder'])->whereUuid('id')->name('quotes.convert-to-order');
        Route::get('/orders', [CpqQuoteAndOrderController::class, 'ordersIndex'])->name('orders.index');
        Route::post('/orders', [CpqQuoteAndOrderController::class, 'ordersStore'])->name('orders.store');

        // Mod 038 & 039: Advanced Pricing & Margin Guardrails + Recurring Subscriptions
        Route::get('/pricing-guardrails', [PricingAndSubscriptionController::class, 'pricingIndex'])->name('pricing-guardrails.index');
        Route::post('/pricing-guardrails', [PricingAndSubscriptionController::class, 'pricingStore'])->name('pricing-guardrails.store');
        Route::post('/pricing-guardrails/{id}/approve-override', [PricingAndSubscriptionController::class, 'pricingApproveOverride'])->whereUuid('id')->name('pricing-guardrails.approve-override');
        Route::get('/subscriptions', [PricingAndSubscriptionController::class, 'subscriptionsIndex'])->name('subscriptions.index');
        Route::post('/subscriptions', [PricingAndSubscriptionController::class, 'subscriptionsStore'])->name('subscriptions.store');
        Route::post('/subscriptions/{id}/transition', [PricingAndSubscriptionController::class, 'subscriptionsTransition'])->whereUuid('id')->name('subscriptions.transition');

        // Mod 040, 041 & 042: DDT/Lieferschein & Deferred Invoicing, Commissions/Enasarco, Self-Service Portal
        Route::get('/ddt-invoices', [DdtCommissionPortalController::class, 'ddtInvoicesIndex'])->name('ddt-invoices.index');
        Route::post('/ddt-invoices', [DdtCommissionPortalController::class, 'ddtInvoicesStore'])->name('ddt-invoices.store');
        Route::get('/commissions', [DdtCommissionPortalController::class, 'commissionsIndex'])->name('commissions.index');
        Route::post('/commissions', [DdtCommissionPortalController::class, 'commissionsStore'])->name('commissions.store');
        Route::get('/portal-transactions', [DdtCommissionPortalController::class, 'portalIndex'])->name('portal-transactions.index');
        Route::post('/portal-transactions', [DdtCommissionPortalController::class, 'portalStore'])->name('portal-transactions.store');

        // Batch 2 — Mod 155, 169, 230: Marketing Automation, Public/Private Tenders, Technical CPQ Configurator
        Route::get('/marketing-campaigns', [MarketingTenderCpqController::class, 'campaignsIndex'])->name('marketing-campaigns.index');
        Route::post('/marketing-campaigns', [MarketingTenderCpqController::class, 'campaignsStore'])->name('marketing-campaigns.store');
        Route::get('/tenders', [MarketingTenderCpqController::class, 'tendersIndex'])->name('tenders.index');
        Route::post('/tenders', [MarketingTenderCpqController::class, 'tendersStore'])->name('tenders.store');
        Route::post('/tenders/{id}/convert-contract', [MarketingTenderCpqController::class, 'tendersConvertContract'])->whereUuid('id')->name('tenders.convert-contract');
        Route::get('/cpq-configurations', [MarketingTenderCpqController::class, 'cpqConfigsIndex'])->name('cpq-configurations.index');
        Route::post('/cpq-configurations', [MarketingTenderCpqController::class, 'cpqConfigsStore'])->name('cpq-configurations.store');

        // Batch 2 — Mod 231, 232, 233: GDO Rebates/PFA, Reverse Logistics/B-Stock, Van Selling
        Route::get('/gdo-rebates', [RebateReturnVanController::class, 'rebatesIndex'])->name('gdo-rebates.index');
        Route::post('/gdo-rebates', [RebateReturnVanController::class, 'rebatesStore'])->name('gdo-rebates.store');
        Route::post('/gdo-rebates/{id}/settle-credit-note', [RebateReturnVanController::class, 'rebatesSettleCreditNote'])->whereUuid('id')->name('gdo-rebates.settle-credit-note');
        Route::get('/reverse-returns', [RebateReturnVanController::class, 'returnsIndex'])->name('reverse-returns.index');
        Route::post('/reverse-returns', [RebateReturnVanController::class, 'returnsStore'])->name('reverse-returns.store');
        Route::get('/van-routes', [RebateReturnVanController::class, 'vanRoutesIndex'])->name('van-routes.index');
        Route::post('/van-routes', [RebateReturnVanController::class, 'vanRoutesStore'])->name('van-routes.store');

        // Batch 2 — Mod 244, 245, 358: Franchising Portal, Post-Delivery Surveys, Corporate Welfare
        Route::get('/franchising-affiliates', [FranchiseSurveyWelfareController::class, 'affiliatesIndex'])->name('franchising-affiliates.index');
        Route::post('/franchising-affiliates', [FranchiseSurveyWelfareController::class, 'affiliatesStore'])->name('franchising-affiliates.store');
        Route::get('/surveys', [FranchiseSurveyWelfareController::class, 'surveysIndex'])->name('surveys.index');
        Route::post('/surveys', [FranchiseSurveyWelfareController::class, 'surveysStore'])->name('surveys.store');
        Route::get('/welfare-conventions', [FranchiseSurveyWelfareController::class, 'welfareIndex'])->name('welfare-conventions.index');
        Route::post('/welfare-conventions', [FranchiseSurveyWelfareController::class, 'welfareStore'])->name('welfare-conventions.store');
    });
