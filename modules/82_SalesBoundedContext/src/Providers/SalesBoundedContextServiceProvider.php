<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\SalesBoundedContext\Models\SbcCommissionStatement;
use Modules\SalesBoundedContext\Models\SbcCorporateWelfareConvention;
use Modules\SalesBoundedContext\Models\SbcCpqConfiguration;
use Modules\SalesBoundedContext\Models\SbcCpqQuote;
use Modules\SalesBoundedContext\Models\SbcCustomerDossier;
use Modules\SalesBoundedContext\Models\SbcDdtInvoice;
use Modules\SalesBoundedContext\Models\SbcFrameworkOrder;
use Modules\SalesBoundedContext\Models\SbcFranchisingAffiliate;
use Modules\SalesBoundedContext\Models\SbcGdoRebateAgreement;
use Modules\SalesBoundedContext\Models\SbcMarketingCampaign;
use Modules\SalesBoundedContext\Models\SbcOmnichannelLead;
use Modules\SalesBoundedContext\Models\SbcOpportunityForecast;
use Modules\SalesBoundedContext\Models\SbcPortalTransaction;
use Modules\SalesBoundedContext\Models\SbcPostDeliverySurvey;
use Modules\SalesBoundedContext\Models\SbcPricingGuardrail;
use Modules\SalesBoundedContext\Models\SbcPublicPrivateTender;
use Modules\SalesBoundedContext\Models\SbcRecurringSubscription;
use Modules\SalesBoundedContext\Models\SbcReverseLogisticsReturn;
use Modules\SalesBoundedContext\Models\SbcVanSellingRoute;
use Modules\SalesBoundedContext\Policies\SalesBoundedContextPolicy;

/**
 * Epic [Dominio 04] SD#139 — Sales Bounded Context (Batch 1: Mod 033-042 & Batch 2: Mod 155-358).
 */
final class SalesBoundedContextServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([
            SbcCustomerDossier::class,
            SbcOmnichannelLead::class,
            SbcOpportunityForecast::class,
            SbcCpqQuote::class,
            SbcFrameworkOrder::class,
            SbcPricingGuardrail::class,
            SbcRecurringSubscription::class,
            SbcDdtInvoice::class,
            SbcCommissionStatement::class,
            SbcPortalTransaction::class,
            SbcMarketingCampaign::class,
            SbcPublicPrivateTender::class,
            SbcCpqConfiguration::class,
            SbcGdoRebateAgreement::class,
            SbcReverseLogisticsReturn::class,
            SbcVanSellingRoute::class,
            SbcFranchisingAffiliate::class,
            SbcPostDeliverySurvey::class,
            SbcCorporateWelfareConvention::class,
        ] as $model) {
            Gate::policy($model, SalesBoundedContextPolicy::class);
        }
    }
}
