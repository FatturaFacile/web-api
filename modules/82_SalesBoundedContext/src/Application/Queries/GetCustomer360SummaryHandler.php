<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Queries;

use Modules\SalesBoundedContext\Models\SbcCpqQuote;
use Modules\SalesBoundedContext\Models\SbcCustomerDossier;
use Modules\SalesBoundedContext\Models\SbcDdtInvoice;
use Modules\SalesBoundedContext\Models\SbcFrameworkOrder;
use Modules\SalesBoundedContext\Models\SbcGdoRebateAgreement;
use Modules\SalesBoundedContext\Models\SbcOpportunityForecast;
use Modules\SalesBoundedContext\Models\SbcPortalTransaction;
use Modules\SalesBoundedContext\Models\SbcPostDeliverySurvey;
use Modules\SalesBoundedContext\Models\SbcPublicPrivateTender;
use Modules\SalesBoundedContext\Models\SbcRecurringSubscription;
use Modules\SalesBoundedContext\Models\SbcReverseLogisticsReturn;

final readonly class GetCustomer360SummaryHandler
{
    /**
     * @return array<string, mixed>
     */
    public function handle(GetCustomer360SummaryQuery $query): array
    {
        $dossier = SbcCustomerDossier::query()
            ->with('subsidiaries')
            ->where('tenant_id', $query->tenantId)
            ->findOrFail($query->dossierId);

        $opportunities = SbcOpportunityForecast::query()
            ->where('tenant_id', $query->tenantId)
            ->where('dossier_id', $dossier->id)
            ->get();

        $quotes = SbcCpqQuote::query()
            ->where('tenant_id', $query->tenantId)
            ->where('dossier_id', $dossier->id)
            ->get();

        $orders = SbcFrameworkOrder::query()
            ->where('tenant_id', $query->tenantId)
            ->where('dossier_id', $dossier->id)
            ->get();

        $subscriptions = SbcRecurringSubscription::query()
            ->where('tenant_id', $query->tenantId)
            ->where('dossier_id', $dossier->id)
            ->get();

        $documents = SbcDdtInvoice::query()
            ->where('tenant_id', $query->tenantId)
            ->where('dossier_id', $dossier->id)
            ->get();

        $portalTxs = SbcPortalTransaction::query()
            ->where('tenant_id', $query->tenantId)
            ->where('dossier_id', $dossier->id)
            ->get();

        $tendersCount = SbcPublicPrivateTender::query()
            ->where('tenant_id', $query->tenantId)
            ->where('dossier_id', $dossier->id)
            ->count();

        $gdoRebatesAccrued = round((float) SbcGdoRebateAgreement::query()
            ->where('tenant_id', $query->tenantId)
            ->where('dossier_id', $dossier->id)
            ->sum('total_provisioned_accrual'), 2);

        $returnsCount = SbcReverseLogisticsReturn::query()
            ->where('tenant_id', $query->tenantId)
            ->where('dossier_id', $dossier->id)
            ->count();

        $surveysAvgNps = round((float) SbcPostDeliverySurvey::query()
            ->where('tenant_id', $query->tenantId)
            ->where('dossier_id', $dossier->id)
            ->avg('nps_score'), 2);

        return [
            'dossier' => $dossier,
            'pipeline' => [
                'opportunities_count' => $opportunities->count(),
                'weighted_pipeline_total' => round((float) $opportunities->sum('weighted_amount'), 2),
                'quotes_count' => $quotes->count(),
                'orders_count' => $orders->count(),
                'active_mrr' => round((float) $subscriptions->sum('mrr_amount'), 2),
                'invoices_and_ddts_count' => $documents->count(),
                'portal_payments_total' => round((float) $portalTxs->where('transaction_type', 'online_payment')->sum('amount'), 2),
                'tenders_count' => $tendersCount,
                'gdo_rebates_accrued' => $gdoRebatesAccrued,
                'reverse_returns_count' => $returnsCount,
                'avg_nps_score' => $surveysAvgNps,
            ],
        ];
    }
}
