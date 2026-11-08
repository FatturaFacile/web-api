<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One parallel book of an asset (statutory/civil, tax/fiscal, IFRS, management): its own depreciation parameters and
 * running values. The statutory book mirrors the values of the asset master record.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $asset_id
 * @property string|null $ledger_type
 * @property string|null $depreciation_method
 * @property int|null $useful_life_months
 * @property string|null $rate_percent
 * @property string|null $declining_balance_factor
 * @property string|null $pro_rata_convention
 * @property CarbonImmutable|null $depreciation_start_date
 * @property string|null $cost
 * @property string|null $salvage_value
 * @property string|null $depreciable_base
 * @property string|null $accumulated_depreciation
 * @property string|null $net_book_value
 * @property string|null $total_expected_units
 * @property string|null $consumed_units
 * @property string|null $last_period_code
 * @property bool $is_fully_depreciated
 * @property bool $posts_to_gl
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class AssetBook extends BaseEnterpriseModel
{
    protected $table = 'eam_asset_books';

    protected function casts(): array
    {
        return [
            'useful_life_months' => 'integer',
            'rate_percent' => 'decimal:4',
            'declining_balance_factor' => 'decimal:4',
            'depreciation_start_date' => 'immutable_date',
            'cost' => 'decimal:2',
            'salvage_value' => 'decimal:2',
            'depreciable_base' => 'decimal:2',
            'accumulated_depreciation' => 'decimal:2',
            'net_book_value' => 'decimal:2',
            'total_expected_units' => 'decimal:2',
            'consumed_units' => 'decimal:2',
            'is_fully_depreciated' => 'boolean',
            'posts_to_gl' => 'boolean',
        ];
    }

    /**
     * The asset the book belongs to.
     *
     * @return BelongsTo<FixedAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }
}
