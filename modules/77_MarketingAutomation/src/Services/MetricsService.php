<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\MarketingAutomation\Models\Campaign;
use Modules\MarketingAutomation\Models\CampaignMetric;

/**
 * CampaignAttributionAndRoiMetric: engagement counters and commercial attribution of one campaign.
 *
 * ROI % = (attributed gross margin - campaign cost) / campaign cost x 100. A campaign without cost has no ROI
 * (null, state `no_cost`); a campaign with cost and no attributed margin reports -100 % (state `no_revenue`).
 */
final class MetricsService
{
    public function recompute(Campaign $campaign): CampaignMetric
    {
        $campaignId = (string) $campaign->id;
        $messages = DB::table('ma_messages')->where('campaign_id', $campaignId)->whereNull('deleted_at');

        $engagement = (clone $messages)->selectRaw(
            "SUM(CASE WHEN sent_at IS NOT NULL THEN 1 ELSE 0 END) as sent,
             SUM(CASE WHEN delivered_at IS NOT NULL OR (sent_at IS NOT NULL AND bounced_at IS NULL AND status IN ('sent','delivered')) THEN 1 ELSE 0 END) as delivered,
             SUM(CASE WHEN first_opened_at IS NOT NULL OR first_clicked_at IS NOT NULL THEN 1 ELSE 0 END) as opens,
             SUM(CASE WHEN first_clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicks,
             SUM(CASE WHEN status = 'hard_bounced' THEN 1 ELSE 0 END) as hard,
             SUM(CASE WHEN status = 'soft_bounced' THEN 1 ELSE 0 END) as soft,
             SUM(CASE WHEN status = 'complained' THEN 1 ELSE 0 END) as complaints"
        )->first();

        $unsubscribes = DB::table('ma_suppressions')->where('campaign_id', $campaignId)->where('reason', 'unsubscribe')->whereNull('deleted_at')->count();

        $byType = DB::table('ma_attributions')->where('campaign_id', $campaignId)->whereNull('deleted_at')
            ->groupBy('document_type')
            ->selectRaw('document_type, COUNT(*) as n, SUM(amount_net) as net, SUM(gross_margin) as margin, SUM(collected_amount) as collected')
            ->get()->keyBy('document_type');

        // A direct invoice (no sales order) is a sale of its own; an invoice of an order is only its invoicing step.
        $directInvoiceNet = (float) DB::table('ma_attributions as a')
            ->join('invoices as i', 'i.id', '=', 'a.document_id')
            ->where('a.campaign_id', $campaignId)->where('a.document_type', 'invoice')->whereNull('a.deleted_at')->whereNull('i.sales_order_id')
            ->sum('a.amount_net');

        $revenue = (float) ($byType->get('sales_order')->net ?? 0) + $directInvoiceNet;
        $margin = (float) ($byType->get('sales_order')->margin ?? 0);
        $cost = (float) $campaign->campaign_cost;
        [$roi, $state] = $this->roi($margin, $cost, $revenue);

        $metric = CampaignMetric::withoutGlobalScopes()->firstOrNew(['campaign_id' => $campaignId]);
        $metric->tenant_id = $campaign->tenant_id;
        $metric->forceFill([
            'sent_count' => (int) ($engagement->sent ?? 0),
            'delivered_count' => (int) ($engagement->delivered ?? 0),
            'unique_opens_count' => (int) ($engagement->opens ?? 0),
            'unique_clicks_count' => (int) ($engagement->clicks ?? 0),
            'unsubscribes_count' => $unsubscribes,
            'hard_bounces_count' => (int) ($engagement->hard ?? 0),
            'soft_bounces_count' => (int) ($engagement->soft ?? 0),
            'spam_complaints_count' => (int) ($engagement->complaints ?? 0),
            'attributed_quotes_count' => (int) ($byType->get('quote')->n ?? 0),
            'attributed_quotes_value' => round((float) ($byType->get('quote')->net ?? 0), 2),
            'attributed_sales_orders_count' => (int) ($byType->get('sales_order')->n ?? 0),
            'attributed_net_revenue_amount' => round($revenue, 2),
            'attributed_invoiced_amount' => round((float) ($byType->get('invoice')->net ?? 0), 2),
            'attributed_collected_amount' => round((float) ($byType->get('invoice')->collected ?? 0), 2),
            'attributed_gross_margin_amount' => round($margin, 2),
            'campaign_cost' => round($cost, 2),
            'calculated_roi_percentage' => $roi,
            'roi_state' => $state,
            'currency' => $campaign->currency,
            'calculated_at' => Carbon::now(),
        ])->save();

        return $metric;
    }

    /**
     * @return array{0: float|null, 1: string}
     */
    public function roi(float $grossMargin, float $cost, float $revenue): array
    {
        if ($cost <= 0.0) {
            return [null, 'no_cost'];
        }
        if ($revenue <= 0.0 && $grossMargin <= 0.0) {
            return [-100.0, 'no_revenue'];
        }

        return [round(($grossMargin - $cost) / $cost * 100, 2), 'computed'];
    }
}
