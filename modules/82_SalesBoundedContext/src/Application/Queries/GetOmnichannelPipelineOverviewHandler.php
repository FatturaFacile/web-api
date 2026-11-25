<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Queries;

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

final readonly class GetOmnichannelPipelineOverviewHandler
{
    /**
     * @return array<string, mixed>
     */
    public function handle(GetOmnichannelPipelineOverviewQuery $query): array
    {
        $tenantId = $query->tenantId;

        return [
            'dossiers_count' => SbcCustomerDossier::query()->where('tenant_id', $tenantId)->count(),
            'leads_count' => SbcOmnichannelLead::query()->where('tenant_id', $tenantId)->count(),
            'weighted_pipeline' => round((float) SbcOpportunityForecast::query()->where('tenant_id', $tenantId)->sum('weighted_amount'), 2),
            'signed_quotes_count' => SbcCpqQuote::query()->where('tenant_id', $tenantId)->where('signature_status', 'signed')->count(),
            'framework_agreements_count' => SbcFrameworkOrder::query()->where('tenant_id', $tenantId)->where('order_kind', 'framework_agreement')->count(),
            'guardrail_breaches_pending' => SbcPricingGuardrail::query()->where('tenant_id', $tenantId)->where('approval_status', 'pending_manager_approval')->count(),
            'active_mrr' => round((float) SbcRecurringSubscription::query()->where('tenant_id', $tenantId)->sum('mrr_amount'), 2),
            'ddt_and_invoices_count' => SbcDdtInvoice::query()->where('tenant_id', $tenantId)->count(),
            'commissions_payable' => round((float) SbcCommissionStatement::query()->where('tenant_id', $tenantId)->sum('net_payable_amount'), 2),
            'portal_settled_total' => round((float) SbcPortalTransaction::query()->where('tenant_id', $tenantId)->where('transaction_type', 'online_payment')->sum('amount'), 2),
            'campaigns_attributed_revenue' => round((float) SbcMarketingCampaign::query()->where('tenant_id', $tenantId)->sum('attributed_revenue'), 2),
            'tenders_pipeline_value' => round((float) SbcPublicPrivateTender::query()->where('tenant_id', $tenantId)->sum('bid_amount'), 2),
            'cpq_configurations_count' => SbcCpqConfiguration::query()->where('tenant_id', $tenantId)->count(),
            'gdo_rebates_accrued' => round((float) SbcGdoRebateAgreement::query()->where('tenant_id', $tenantId)->sum('total_provisioned_accrual'), 2),
            'reverse_returns_count' => SbcReverseLogisticsReturn::query()->where('tenant_id', $tenantId)->count(),
            'van_routes_reconciled_count' => SbcVanSellingRoute::query()->where('tenant_id', $tenantId)->count(),
            'franchising_royalties_total' => round((float) SbcFranchisingAffiliate::query()->where('tenant_id', $tenantId)->sum('total_fee_invoiceable'), 2),
            'detractor_alerts_open' => SbcPostDeliverySurvey::query()->where('tenant_id', $tenantId)->where('detractor_recovery_alert_triggered', true)->count(),
            'welfare_employer_quota_total' => round((float) SbcCorporateWelfareConvention::query()->where('tenant_id', $tenantId)->sum('employer_welfare_quota_amount'), 2),
        ];
    }
}
