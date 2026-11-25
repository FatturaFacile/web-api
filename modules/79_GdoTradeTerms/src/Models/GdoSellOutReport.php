<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * POS sell-out of a product reported by the retailer for a period (and optionally a store).
 *
 * @property string $contract_id
 * @property string|null $delivery_point_id
 * @property string $product_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $quantity
 * @property string $amount
 * @property string $source
 * @property string $natural_key
 * @property Carbon|null $reported_at
 */
final class GdoSellOutReport extends BaseEnterpriseModel
{
    protected $table = 'gdt_sell_out_reports';

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'quantity' => 'decimal:4', 'amount' => 'decimal:4', 'reported_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<GdoDeliveryPoint, $this>
     */
    public function deliveryPoint(): BelongsTo
    {
        return $this->belongsTo(GdoDeliveryPoint::class, 'delivery_point_id');
    }
}
