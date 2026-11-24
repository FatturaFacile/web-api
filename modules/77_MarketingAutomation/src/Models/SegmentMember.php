<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Mod 155 Marketing Automation — SegmentMember.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $segment_id
 * @property string $contact_id
 * @property int $r_score
 * @property int $f_score
 * @property int $m_score
 * @property string $rfm_label
 * @property string $locale
 * @property int $recency_days
 * @property int $frequency_orders
 * @property string $monetary_value
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class SegmentMember extends BaseEnterpriseModel
{
    protected $table = 'ma_segment_members';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'r_score' => 'integer',
            'f_score' => 'integer',
            'm_score' => 'integer',
            'recency_days' => 'integer',
            'frequency_orders' => 'integer',
            'monetary_value' => 'decimal:2',
        ];
    }
}
