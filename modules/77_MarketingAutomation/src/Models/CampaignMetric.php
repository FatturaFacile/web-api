<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Mod 155 Marketing Automation — CampaignMetric.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $campaign_id
 * @property int $sent_count
 * @property int $delivered_count
 * @property int $unique_opens_count
 * @property int $unique_clicks_count
 * @property int $unsubscribes_count
 * @property int $hard_bounces_count
 * @property int $soft_bounces_count
 * @property int $spam_complaints_count
 * @property int $attributed_quotes_count
 * @property int $attributed_sales_orders_count
 * @property string|null $calculated_roi_percentage
 * @property string $roi_state
 * @property string $currency
 * @property string $attributed_quotes_value
 * @property string $attributed_net_revenue_amount
 * @property string $attributed_invoiced_amount
 * @property string $attributed_collected_amount
 * @property string $attributed_gross_margin_amount
 * @property string $campaign_cost
 * @property \Illuminate\Support\Carbon|null $calculated_at
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class CampaignMetric extends BaseEnterpriseModel
{
    protected $table = 'ma_metrics';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_count' => 'integer',
            'delivered_count' => 'integer',
            'unique_opens_count' => 'integer',
            'unique_clicks_count' => 'integer',
            'unsubscribes_count' => 'integer',
            'hard_bounces_count' => 'integer',
            'soft_bounces_count' => 'integer',
            'spam_complaints_count' => 'integer',
            'attributed_quotes_count' => 'integer',
            'attributed_quotes_value' => 'decimal:2',
            'attributed_sales_orders_count' => 'integer',
            'attributed_net_revenue_amount' => 'decimal:2',
            'attributed_invoiced_amount' => 'decimal:2',
            'attributed_collected_amount' => 'decimal:2',
            'attributed_gross_margin_amount' => 'decimal:2',
            'campaign_cost' => 'decimal:2',
            'calculated_roi_percentage' => 'decimal:2',
            'calculated_at' => 'datetime',
        ];
    }
}
