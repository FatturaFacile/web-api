<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\MarketingAutomation\Models\CampaignMetric;

/**
 * @mixin CampaignMetric
 */
final class CampaignMetricResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'campaign_id' => $this->campaign_id,
            'sent_count' => $this->sent_count,
            'delivered_count' => $this->delivered_count,
            'unique_opens_count' => $this->unique_opens_count,
            'unique_clicks_count' => $this->unique_clicks_count,
            'unsubscribes_count' => $this->unsubscribes_count,
            'hard_bounces_count' => $this->hard_bounces_count,
            'soft_bounces_count' => $this->soft_bounces_count,
            'spam_complaints_count' => $this->spam_complaints_count,
            'attributed_quotes_count' => $this->attributed_quotes_count,
            'attributed_quotes_value' => (float) $this->attributed_quotes_value,
            'attributed_sales_orders_count' => $this->attributed_sales_orders_count,
            'attributed_net_revenue_amount' => (float) $this->attributed_net_revenue_amount,
            'attributed_invoiced_amount' => (float) $this->attributed_invoiced_amount,
            'attributed_collected_amount' => (float) $this->attributed_collected_amount,
            'attributed_gross_margin_amount' => (float) $this->attributed_gross_margin_amount,
            'campaign_cost' => (float) $this->campaign_cost,
            'calculated_roi_percentage' => $this->calculated_roi_percentage === null ? null : (float) $this->calculated_roi_percentage,
            'roi_state' => $this->roi_state,
            'currency' => $this->currency,
            'calculated_at' => $this->calculated_at?->toIso8601String(),
        ];
    }
}
