<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Mod 155 Marketing Automation — Segment.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $code
 * @property string $name
 * @property string|null $description
 * @property array<string, mixed>|null $criteria
 * @property bool $auto_refresh_hourly
 * @property int $eligible_contacts_count
 * @property int $excluded_insolvent_count
 * @property int $excluded_no_consent_count
 * @property array<string, int>|null $rfm_breakdown
 * @property Carbon|null $last_evaluated_at
 * @property array<string, mixed>|null $criteria
 * @property array<string, mixed>|null $rfm_breakdown
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Segment extends BaseEnterpriseModel
{
    protected $table = 'ma_segments';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'auto_refresh_hourly' => 'boolean',
            'eligible_contacts_count' => 'integer',
            'excluded_insolvent_count' => 'integer',
            'excluded_no_consent_count' => 'integer',
            'rfm_breakdown' => 'array',
            'last_evaluated_at' => 'datetime',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(SegmentMember::class, 'segment_id');
    }
}
