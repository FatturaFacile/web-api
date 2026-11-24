<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A downtime, setup, micro-stop, scrap or rework reason of the structured catalogue the MES Core classifies stops with.
 * The classification tells the OEE engine whether the time is an availability loss or planned (excluded) time.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $category
 * @property string $classification
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, MfgReasonCodeLabel> $labels
 */
final class MfgReasonCode extends BaseEnterpriseModel
{
    /** The categories of a reason code. */
    public const array Categories = ['UnplannedDowntime', 'PlannedDowntime', 'Setup', 'MicroStop', 'Scrap', 'Rework'];

    /** How the OEE engine treats the time of a reason code. */
    public const array Classifications = ['AvailabilityLoss', 'PlannedExcluded', 'NotApplicable'];

    protected $table = 'mfg_reason_codes';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<MfgReasonCodeLabel, $this>
     */
    public function labels(): HasMany
    {
        return $this->hasMany(MfgReasonCodeLabel::class, 'reason_code_id');
    }
}
