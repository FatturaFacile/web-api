<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\SalesBoundedContext\Domain\Enums\RfmSegmentCode;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $dossier_id
 * @property string $campaign_code
 * @property string $name
 * @property string $channel
 * @property int $rfm_recency_score
 * @property int $rfm_frequency_score
 * @property int $rfm_monetary_score
 * @property RfmSegmentCode|null $rfm_segment
 * @property array<int, array<string, mixed>>|null $journey_definition
 * @property bool $exclude_insolvent_overdue
 * @property int $excluded_insolvent_count
 * @property bool $gdpr_nlpd_lcsl_consent_verified
 * @property int $opted_out_suppressed_count
 * @property int $eligible_recipients_count
 * @property string $campaign_cost
 * @property string $attributed_revenue
 * @property string $roi_pct
 * @property string $currency
 * @property string $status
 */
final class SbcMarketingCampaign extends BaseEnterpriseModel
{
    protected $table = 'sbc_marketing_campaigns';

    protected function casts(): array
    {
        return [
            'rfm_recency_score' => 'integer',
            'rfm_frequency_score' => 'integer',
            'rfm_monetary_score' => 'integer',
            'rfm_segment' => RfmSegmentCode::class,
            'journey_definition' => 'array',
            'exclude_insolvent_overdue' => 'boolean',
            'excluded_insolvent_count' => 'integer',
            'gdpr_nlpd_lcsl_consent_verified' => 'boolean',
            'opted_out_suppressed_count' => 'integer',
            'eligible_recipients_count' => 'integer',
            'campaign_cost' => 'decimal:2',
            'attributed_revenue' => 'decimal:2',
            'roi_pct' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<SbcCustomerDossier, $this>
     */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(SbcCustomerDossier::class, 'dossier_id');
    }
}
