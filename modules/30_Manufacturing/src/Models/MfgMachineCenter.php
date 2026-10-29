<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AssetManagement\Models\Asset;

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

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(MfgWorkCenter::class, 'work_center_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function isAvailable(): bool
    {
        return $this->status === 'active';
    }
}
