<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DepreciationSchedule extends BaseEnterpriseModel
{
    protected $table = 'eam_depreciation_schedules';

    protected function casts(): array
    {
        return [
            'opening_book_value' => 'decimal:2',
            'depreciation_amount' => 'decimal:2',
            'closing_book_value' => 'decimal:2',
            'accumulated_depreciation' => 'decimal:2',
            'units_produced' => 'decimal:2',
            'calculated_at' => 'datetime',
            'posted_at' => 'datetime',
            'calculation_snapshot' => 'array',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DepreciationRun::class, 'run_id');
    }
}
