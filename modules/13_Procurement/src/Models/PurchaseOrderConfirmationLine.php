<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ordered versus confirmed quantity, price and delivery date of one purchase order line, with the variances.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $purchase_order_confirmation_id
 * @property string $purchase_order_line_id
 * @property string $ordered_quantity
 * @property string $confirmed_quantity
 * @property string $ordered_unit_price
 * @property string $confirmed_unit_price
 * @property Carbon|null $requested_delivery_date
 * @property Carbon|null $confirmed_delivery_date
 * @property string $quantity_variance
 * @property string $quantity_variance_base
 * @property string $price_variance
 * @property string $price_variance_percent
 * @property string $line_total_variance
 * @property int $delivery_delay_days
 * @property string|null $variance_reason
 */
final class PurchaseOrderConfirmationLine extends BaseEnterpriseModel
{
    protected $table = 'purchase_order_confirmation_lines';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ordered_quantity' => 'decimal:4',
            'confirmed_quantity' => 'decimal:4',
            'ordered_unit_price' => 'decimal:4',
            'confirmed_unit_price' => 'decimal:4',
            'requested_delivery_date' => 'date',
            'confirmed_delivery_date' => 'date',
            'quantity_variance' => 'decimal:4',
            'quantity_variance_base' => 'decimal:4',
            'price_variance' => 'decimal:4',
            'price_variance_percent' => 'decimal:4',
            'line_total_variance' => 'decimal:2',
            'delivery_delay_days' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrderConfirmation, $this>
     */
    public function confirmation(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderConfirmation::class, 'purchase_order_confirmation_id');
    }

    /**
     * @return BelongsTo<PurchaseOrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }
}
