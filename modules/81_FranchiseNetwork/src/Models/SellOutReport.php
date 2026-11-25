<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\FranchiseNetwork\Domain\Enums\SellOutSource;

/**
 * Net sell-out of a store for a month, from its POS or fiscal printers, with the figures the benchmark needs.
 *
 * @property string $store_id
 * @property string $period
 * @property string $gross_sales
 * @property string $returns
 * @property string $discounts
 * @property string $net_sales
 * @property int $tickets_count
 * @property int $visitors_count
 * @property string $cost_of_goods_sold
 * @property string $average_inventory_value
 * @property SellOutSource $source
 * @property string|null $pos_reference
 * @property Carbon|null $submitted_at
 */
final class SellOutReport extends BaseEnterpriseModel
{
    protected $table = 'frn_sell_out_reports';

    protected function casts(): array
    {
        return [
            'gross_sales' => 'decimal:2',
            'returns' => 'decimal:2',
            'discounts' => 'decimal:2',
            'net_sales' => 'decimal:2',
            'tickets_count' => 'integer',
            'visitors_count' => 'integer',
            'cost_of_goods_sold' => 'decimal:2',
            'average_inventory_value' => 'decimal:2',
            'source' => SellOutSource::class,
            'submitted_at' => 'datetime',
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
