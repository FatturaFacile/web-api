<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Mod 155 Marketing Automation — Campaign.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $name
 * @property string $status
 * @property string $channel
 * @property string $trigger_type
 * @property string|null $segment_id
 * @property string $provider
 * @property array<string, mixed>|null $template_mjml_json
 * @property array<string, mixed>|null $ab_test_split_variant_json
 * @property string|null $utm_campaign_code
 * @property string $attribution_model
 * @property int $attribution_window_days
 * @property string $currency
 * @property string $campaign_cost
 * @property string|null $coupon_code
 * @property array<string, mixed>|null $template_mjml_json
 * @property array<string, mixed>|null $ab_test_split_variant_json
 * @property \Illuminate\Support\Carbon|null $scheduled_at
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $sender_name
 * @property string|null $sender_email
 * @property string|null $sender_postal_address
 */
final class Campaign extends BaseEnterpriseModel
{
    protected $table = 'ma_campaigns';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template_mjml_json' => 'array',
            'ab_test_split_variant_json' => 'array',
            'attribution_window_days' => 'integer',
            'campaign_cost' => 'decimal:2',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(JourneyStep::class, 'campaign_id');
    }

    public function metric(): HasOne
    {
        return $this->hasOne(CampaignMetric::class, 'campaign_id');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class, 'segment_id');
    }
}
