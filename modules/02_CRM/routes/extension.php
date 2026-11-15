<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CRM\Http\Controllers\AutomationSequenceController;
use Modules\CRM\Http\Controllers\DealRegistrationController;
use Modules\CRM\Http\Controllers\DiscountThresholdController;
use Modules\CRM\Http\Controllers\MdfClaimController;
use Modules\CRM\Http\Controllers\MdfFundController;
use Modules\CRM\Http\Controllers\PartnerController;
use Modules\CRM\Http\Controllers\PartnerTierController;
use Modules\CRM\Http\Controllers\PricingRuleController;
use Modules\CRM\Http\Controllers\QuoteConfigurationController;
use Modules\CRM\Http\Controllers\SalesQuotaController;
use Modules\CRM\Http\Controllers\SalesTerritoryController;

/*
| CRM extension: territories and quotas, partners (PRM), CPQ and marketing automation. Same access rules as the rest of the
| CRM: module.access:crm gives reads to every member and writes to the CRM write roles; approvals are reserved by the policy.
*/
Route::prefix('api/v1/crm')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:crm'])
    ->name('crm.')
    ->where(['id' => '[0-9a-fA-F-]{36}'])
    ->group(function (): void {
        // Territories and quotas
        Route::post('territories/assign', [SalesTerritoryController::class, 'assign'])->name('territories.assign');
        Route::get('territories/{id}/accounts', [SalesTerritoryController::class, 'accounts'])->name('territories.accounts');
        Route::post('accounts/{id}/territory', [SalesTerritoryController::class, 'assignAccount'])->name('accounts.territory');
        Route::get('territories', [SalesTerritoryController::class, 'index'])->name('territories.index');
        Route::post('territories', [SalesTerritoryController::class, 'store'])->name('territories.store');
        Route::get('territories/{id}', [SalesTerritoryController::class, 'show'])->name('territories.show');
        Route::put('territories/{id}', [SalesTerritoryController::class, 'update'])->name('territories.update');
        Route::delete('territories/{id}', [SalesTerritoryController::class, 'destroy'])->name('territories.destroy');

        Route::get('sales-quotas', [SalesQuotaController::class, 'index'])->name('sales-quotas.index');
        Route::post('sales-quotas', [SalesQuotaController::class, 'store'])->name('sales-quotas.store');
        Route::get('sales-quotas/{id}', [SalesQuotaController::class, 'show'])->name('sales-quotas.show');
        Route::put('sales-quotas/{id}', [SalesQuotaController::class, 'update'])->name('sales-quotas.update');
        Route::delete('sales-quotas/{id}', [SalesQuotaController::class, 'destroy'])->name('sales-quotas.destroy');

        // Partners (PRM)
        Route::get('partner-tiers', [PartnerTierController::class, 'index'])->name('partner-tiers.index');
        Route::post('partner-tiers', [PartnerTierController::class, 'store'])->name('partner-tiers.store');
        Route::get('partner-tiers/{id}', [PartnerTierController::class, 'show'])->name('partner-tiers.show');
        Route::put('partner-tiers/{id}', [PartnerTierController::class, 'update'])->name('partner-tiers.update');
        Route::delete('partner-tiers/{id}', [PartnerTierController::class, 'destroy'])->name('partner-tiers.destroy');

        Route::post('partners/evaluate-tiers', [PartnerController::class, 'evaluateTiers'])->name('partners.evaluate-tiers');
        Route::post('partners/{id}/evaluate-tier', [PartnerController::class, 'evaluateTier'])->name('partners.evaluate-tier');
        Route::get('partners', [PartnerController::class, 'index'])->name('partners.index');
        Route::post('partners', [PartnerController::class, 'store'])->name('partners.store');
        Route::get('partners/{id}', [PartnerController::class, 'show'])->name('partners.show');
        Route::put('partners/{id}', [PartnerController::class, 'update'])->name('partners.update');
        Route::delete('partners/{id}', [PartnerController::class, 'destroy'])->name('partners.destroy');

        Route::post('deal-registrations/expire', [DealRegistrationController::class, 'expire'])->name('deal-registrations.expire');
        Route::get('deal-registrations', [DealRegistrationController::class, 'index'])->name('deal-registrations.index');
        Route::post('deal-registrations', [DealRegistrationController::class, 'store'])->name('deal-registrations.store');
        Route::get('deal-registrations/{id}', [DealRegistrationController::class, 'show'])->name('deal-registrations.show');
        Route::post('deal-registrations/{id}/approve', [DealRegistrationController::class, 'approve'])->name('deal-registrations.approve');
        Route::post('deal-registrations/{id}/reject', [DealRegistrationController::class, 'reject'])->name('deal-registrations.reject');
        Route::post('deal-registrations/{id}/won', [DealRegistrationController::class, 'won'])->name('deal-registrations.won');

        Route::get('mdf-funds', [MdfFundController::class, 'index'])->name('mdf-funds.index');
        Route::post('mdf-funds', [MdfFundController::class, 'store'])->name('mdf-funds.store');
        Route::get('mdf-funds/{id}', [MdfFundController::class, 'show'])->name('mdf-funds.show');
        Route::delete('mdf-funds/{id}', [MdfFundController::class, 'destroy'])->name('mdf-funds.destroy');
        Route::get('mdf-funds/{id}/claims', [MdfFundController::class, 'claims'])->name('mdf-funds.claims');
        Route::post('mdf-funds/{id}/claims', [MdfFundController::class, 'submitClaim'])->name('mdf-funds.claims.store');
        Route::get('mdf-claims', [MdfClaimController::class, 'index'])->name('mdf-claims.index');
        Route::post('mdf-claims/{id}/approve', [MdfClaimController::class, 'approve'])->name('mdf-claims.approve');
        Route::post('mdf-claims/{id}/reject', [MdfClaimController::class, 'reject'])->name('mdf-claims.reject');
        Route::post('mdf-claims/{id}/pay', [MdfClaimController::class, 'pay'])->name('mdf-claims.pay');

        // CPQ
        Route::get('pricing-rules', [PricingRuleController::class, 'index'])->name('pricing-rules.index');
        Route::post('pricing-rules', [PricingRuleController::class, 'store'])->name('pricing-rules.store');
        Route::get('pricing-rules/{id}', [PricingRuleController::class, 'show'])->name('pricing-rules.show');
        Route::put('pricing-rules/{id}', [PricingRuleController::class, 'update'])->name('pricing-rules.update');
        Route::delete('pricing-rules/{id}', [PricingRuleController::class, 'destroy'])->name('pricing-rules.destroy');

        Route::get('discount-thresholds', [DiscountThresholdController::class, 'index'])->name('discount-thresholds.index');
        Route::post('discount-thresholds', [DiscountThresholdController::class, 'store'])->name('discount-thresholds.store');
        Route::get('discount-thresholds/{id}', [DiscountThresholdController::class, 'show'])->name('discount-thresholds.show');
        Route::put('discount-thresholds/{id}', [DiscountThresholdController::class, 'update'])->name('discount-thresholds.update');
        Route::delete('discount-thresholds/{id}', [DiscountThresholdController::class, 'destroy'])->name('discount-thresholds.destroy');

        Route::post('quote-configurations/preview', [QuoteConfigurationController::class, 'preview'])->name('quote-configurations.preview');
        Route::get('quote-configurations', [QuoteConfigurationController::class, 'index'])->name('quote-configurations.index');
        Route::post('quote-configurations', [QuoteConfigurationController::class, 'store'])->name('quote-configurations.store');
        Route::get('quote-configurations/{id}', [QuoteConfigurationController::class, 'show'])->name('quote-configurations.show');
        Route::put('quote-configurations/{id}', [QuoteConfigurationController::class, 'update'])->name('quote-configurations.update');
        Route::delete('quote-configurations/{id}', [QuoteConfigurationController::class, 'destroy'])->name('quote-configurations.destroy');
        Route::post('quote-configurations/{id}/submit', [QuoteConfigurationController::class, 'submit'])->name('quote-configurations.submit');
        Route::post('quote-configurations/{id}/approve', [QuoteConfigurationController::class, 'approve'])->name('quote-configurations.approve');
        Route::post('quote-configurations/{id}/reject', [QuoteConfigurationController::class, 'reject'])->name('quote-configurations.reject');

        // Marketing automation
        Route::post('automation-sequences/run', [AutomationSequenceController::class, 'run'])->name('automation-sequences.run');
        Route::get('automation-sequences', [AutomationSequenceController::class, 'index'])->name('automation-sequences.index');
        Route::post('automation-sequences', [AutomationSequenceController::class, 'store'])->name('automation-sequences.store');
        Route::get('automation-sequences/{id}', [AutomationSequenceController::class, 'show'])->name('automation-sequences.show');
        Route::put('automation-sequences/{id}', [AutomationSequenceController::class, 'update'])->name('automation-sequences.update');
        Route::delete('automation-sequences/{id}', [AutomationSequenceController::class, 'destroy'])->name('automation-sequences.destroy');
        Route::post('automation-sequences/{id}/enroll', [AutomationSequenceController::class, 'enroll'])->name('automation-sequences.enroll');
        Route::get('automation-sequences/{id}/enrollments', [AutomationSequenceController::class, 'enrollments'])->name('automation-sequences.enrollments');
        Route::post('automation-enrollments/{id}/exit', [AutomationSequenceController::class, 'exitEnrollment'])->name('automation-enrollments.exit');
        Route::get('automation-enrollments/{id}/logs', [AutomationSequenceController::class, 'logs'])->name('automation-enrollments.logs');
    });
