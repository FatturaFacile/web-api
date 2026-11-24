<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use App\Models\Product;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $requested_date
 * @property Carbon|null $atp_promised_date
 */
final class SalesOrderItem extends BaseEnterpriseModel
{
    protected $table = 'sales_order_items';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'quantity_reserved' => 'decimal:4',
            'quantity_delivered' => 'decimal:4',
            'quantity_invoiced' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'discount_percentage' => 'decimal:2',
            'line_total' => 'decimal:2',
            'cost_price' => 'decimal:4',
            'quantity_cancelled' => 'decimal:4',
            'is_backorder' => 'boolean',
            'replenishment_triggered' => 'boolean',
            'requested_date' => 'date',
            'atp_promised_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<SalesOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return BelongsTo<SalesOrderItem, $this>
     */
    public function parentLine(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_line_id');
    }

    /**
     * @return HasMany<SalesOrderItem, $this>
     */
    public function backorderLines(): HasMany
    {
        return $this->hasMany(self::class, 'parent_line_id');
    }

    public function getRemainingToDeliverAttribute(): float
    {
        return max(0.0, (float) $this->quantity - (float) $this->quantity_delivered - (float) $this->quantity_cancelled);
    }

    public function getRemainingToInvoiceAttribute(): float
    {
        return max(0.0, (float) $this->quantity - (float) $this->quantity_invoiced);
    }
}
