<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model of the depreciation schedule records of the asset management module.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $asset_id
 * @property string|null $run_id
 * @property string|null $ledger_type
 * @property string|null $period_code
 * @property string|null $period_type
 * @property string|null $depreciation_method
 * @property string|null $opening_book_value
 * @property string|null $depreciation_amount
 * @property string|null $closing_book_value
 * @property string|null $accumulated_depreciation
 * @property string|null $units_produced
 * @property string|null $status
 * @property string|null $journal_entry_id
 * @property Carbon|null $calculated_at
 * @property Carbon|null $posted_at
 * @property array<string, mixed>|null $calculation_snapshot
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
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
