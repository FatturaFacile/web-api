<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Queries;

use Modules\SalesBoundedContext\Models\SbcCommissionStatement;
use Modules\SalesBoundedContext\Models\SbcCpqQuote;
use Modules\SalesBoundedContext\Models\SbcCustomerDossier;
use Modules\SalesBoundedContext\Models\SbcDdtInvoice;
use Modules\SalesBoundedContext\Models\SbcFrameworkOrder;
use Modules\SalesBoundedContext\Models\SbcOmnichannelLead;
use Modules\SalesBoundedContext\Models\SbcOpportunityForecast;
use Modules\SalesBoundedContext\Models\SbcPortalTransaction;
use Modules\SalesBoundedContext\Models\SbcPricingGuardrail;
use Modules\SalesBoundedContext\Models\SbcRecurringSubscription;

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
        ];
    }
}
