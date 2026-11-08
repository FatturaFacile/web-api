<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A cumulative meter reading of an asset (operating hours, kilometres, cycles), driving meter-based maintenance plans.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $asset_id
 * @property string|null $meter_code
 * @property string|null $reading_value
 * @property Carbon|null $read_at
 * @property string|null $source
 * @property string|null $recorded_by_user_id
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class AssetMeterReading extends BaseEnterpriseModel
{
    protected $table = 'eam_asset_meter_readings';

    protected function casts(): array
    {
        return [
            'reading_value' => 'decimal:2',
            'read_at' => 'datetime',
        ];
    }

    /**
     * The asset the reading belongs to.
     *
     * @return BelongsTo<FixedAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }
}
