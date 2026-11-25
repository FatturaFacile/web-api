<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\FranchiseNetwork\Domain\Enums\LoyaltyType;

/**
 * Points earned or redeemed by a customer of the shared loyalty programme in a store.
 *
 * @property string $store_id
 * @property string|null $earned_store_id
 * @property string $customer_ref
 * @property LoyaltyType $type
 * @property int $points
 * @property string $value_amount
 * @property Carbon $occurred_at
 * @property string $period
 * @property string|null $settled_run_id
 */
final class LoyaltyTransaction extends BaseEnterpriseModel
{
    protected $table = 'frn_loyalty_transactions';

    protected function casts(): array
    {
        return [
            'type' => LoyaltyType::class,
            'points' => 'integer',
            'value_amount' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FranchiseStore, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(FranchiseStore::class, 'store_id');
    }
}
