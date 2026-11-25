<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Monthly purchases of a store from the central warehouse or the approved suppliers against the contractual minimum.
 *
 * @property string $store_id
 * @property string $period
 * @property string $purchased_amount
 * @property string $required_amount
 * @property bool $compliant
 */
final class PurchaseRecord extends BaseEnterpriseModel
{
    protected $table = 'frn_purchase_records';

    protected function casts(): array
    {
        return [
            'purchased_amount' => 'decimal:2',
            'required_amount' => 'decimal:2',
            'compliant' => 'boolean',
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
