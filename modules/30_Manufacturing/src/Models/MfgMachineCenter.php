<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\AssetManagement\Models\FixedAsset;

/**
 * A machine of a work centre, optionally linked to the fixed asset that represents it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $machine_code
 * @property string $name
 * @property string $work_center_id
 * @property string|null $asset_id
 * @property string $hourly_rate
 * @property string $power_kw
 * @property string $status
 * @property Carbon|null $last_serviced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgMachineCenter extends BaseEnterpriseModel
{
    protected $table = 'mfg_machine_centers';

    protected function casts(): array
    {
        return [
            'hourly_rate' => 'decimal:4',
            'power_kw' => 'decimal:2',
            'last_serviced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MfgWorkCenter, $this>
     */
    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(MfgWorkCenter::class, 'work_center_id');
    }

    /**
     * @return BelongsTo<FixedAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    /**
     * Whether the machine can be scheduled.
     */
    public function isAvailable(): bool
    {
        return $this->status === 'active';
    }
}
