<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\FranchiseNetwork\Domain\Enums\RunStatus;

/**
 * Monthly royalty and marketing fund billing run of the franchisor.
 *
 * @property string $period
 * @property RunStatus $status
 * @property int $stores_count
 * @property string $total_royalty
 * @property string $total_marketing
 * @property string $total_minimum_adjustment
 * @property string $total_loyalty_settlement
 * @property string $total_net
 * @property string $currency
 * @property Carbon|null $calculated_at
 * @property Carbon|null $invoiced_at
 */
final class RoyaltyRun extends BaseEnterpriseModel
{
    protected $table = 'frn_royalty_runs';

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'stores_count' => 'integer',
            'total_royalty' => 'decimal:2',
            'total_marketing' => 'decimal:2',
            'total_minimum_adjustment' => 'decimal:2',
            'total_loyalty_settlement' => 'decimal:2',
            'total_net' => 'decimal:2',
            'calculated_at' => 'datetime',
            'invoiced_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<RoyaltyStatement, $this>
     */
    public function statements(): HasMany
    {
        return $this->hasMany(RoyaltyStatement::class, 'run_id');
    }

    /**
     * @return HasMany<CollectionBatch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(CollectionBatch::class, 'run_id');
    }
}
