<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Application\Queries;

use App\Models\Tenant;
use Modules\MarketingAutomation\Models\Campaign;
use Modules\MarketingAutomation\Models\CampaignMetric;
use Modules\MarketingAutomation\Services\CurrencyRateService;
use Modules\MarketingAutomation\Services\MetricsService;

/**
 * Marketing ROI dashboard: the totals of every campaign normalised to the reporting currency of the tenant (CHF / EUR)
 * plus one row per campaign.
 */
final readonly class GetMarketingRoiDashboardHandler
{
    private const array AMOUNTS = ['attributed_quotes_value', 'attributed_net_revenue_amount', 'attributed_invoiced_amount', 'attributed_collected_amount', 'attributed_gross_margin_amount', 'campaign_cost'];

    private const array COUNTERS = ['sent_count', 'delivered_count', 'unique_opens_count', 'unique_clicks_count', 'unsubscribes_count', 'hard_bounces_count', 'soft_bounces_count', 'spam_complaints_count', 'attributed_quotes_count', 'attributed_sales_orders_count'];

    public function __construct(
        private CurrencyRateService $rates,
        private MetricsService $metrics,
    ) {}

    /**
     * @return array{currency: string, totals: array<string, mixed>, campaigns: list<array<string, mixed>>}
     */
    public function handle(GetMarketingRoiDashboardQuery $query): array
    {
        $currency = strtoupper($query->currency ?? (string) (Tenant::query()->whereKey($query->tenantId)->value('default_currency') ?? 'CHF'));

        $campaigns = Campaign::query()->where('tenant_id', $query->tenantId)
            ->when($query->campaignId !== null, static fn ($q) => $q->whereKey($query->campaignId))
            ->where('status', '!=', 'archived')->orderByDesc('created_at')->get();

        $totals = array_fill_keys([...self::COUNTERS, ...self::AMOUNTS], 0);
        $rows = [];

        foreach ($campaigns as $campaign) {
            $metric = CampaignMetric::query()->where('campaign_id', $campaign->id)->first() ?? $this->metrics->recompute($campaign);
            $rate = $this->rates->rate($query->tenantId, (string) $metric->currency, $currency);

            $row = ['campaign_id' => (string) $campaign->id, 'name' => $campaign->name, 'status' => $campaign->status, 'channel' => $campaign->channel, 'trigger_type' => $campaign->trigger_type];
            foreach (self::COUNTERS as $counter) {
                $row[$counter] = (int) $metric->{$counter};
                $totals[$counter] += $row[$counter];
            }
            foreach (self::AMOUNTS as $amount) {
                $row[$amount] = round((float) $metric->{$amount} * $rate, 2);
                $totals[$amount] += $row[$amount];
            }
            $row['calculated_roi_percentage'] = $metric->calculated_roi_percentage === null ? null : (float) $metric->calculated_roi_percentage;
            $row['roi_state'] = $metric->roi_state;
            $rows[] = $row;
        }

        [$roi, $state] = $this->metrics->roi($totals['attributed_gross_margin_amount'], $totals['campaign_cost'], $totals['attributed_net_revenue_amount']);
        $totals['calculated_roi_percentage'] = $roi;
        $totals['roi_state'] = $state;

        return ['currency' => $currency, 'totals' => $totals, 'campaigns' => $rows];
    }
}
