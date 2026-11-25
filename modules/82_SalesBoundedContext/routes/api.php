<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\SalesBoundedContext\Http\Controllers\CpqQuoteAndOrderController;
use Modules\SalesBoundedContext\Http\Controllers\Customer360DossierController;
use Modules\SalesBoundedContext\Http\Controllers\DdtCommissionPortalController;
use Modules\SalesBoundedContext\Http\Controllers\LeadPipelineController;
use Modules\SalesBoundedContext\Http\Controllers\PricingAndSubscriptionController;
use Modules\SalesBoundedContext\Http\Controllers\SalesBoundedContextConfigController;

/*
|--------------------------------------------------------------------------
| Epic [Dominio 04] SD#139 — Sales Bounded Context (Batch 1: Mod 033-042)
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
    });
