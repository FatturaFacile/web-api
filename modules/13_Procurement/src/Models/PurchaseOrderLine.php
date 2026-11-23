<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A line of a purchase order: ordered, received, invoiced and returned quantities at the order unit price.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $purchase_order_id
 * @property int $line_number
 * @property string|null $supplier_catalog_id
 * @property string|null $supplier_contract_line_id
 * @property string|null $rfq_line_id
 * @property string|null $supplier_bid_line_id
 * @property string $description
 * @property string $quantity_ordered
 * @property string $quantity_received
 * @property string $quantity_invoiced
 * @property string $quantity_cancelled
 * @property string $quantity_returned
 * @property string $uom
 * @property string $unit_price
 * @property string|null $list_unit_price
 * @property string|null $unit_weight
 * @property string $tax_rate
 * @property string|null $line_net_amount
 * @property string|null $line_tax_amount
 * @property string|null $line_total_amount
 * @property Carbon|null $expected_delivery_date
 * @property bool $is_closed
 * @property string|null $base_uom
 * @property string $uom_conversion_factor
 * @property string $quantity_base_ordered
 * @property string $quantity_base_received
 * @property string|null $quantity_confirmed
 * @property list<float|int|string>|null $discount_cascade
 * @property string $discount_percent_effective
 * @property Carbon|null $confirmed_delivery_date
 * @property Carbon|null $promised_delivery_date
 * @property string|null $sales_order_line_id
 * @property string|null $source_line_id
 * @property PurchaseOrder|null $purchaseOrder
 */
final class PurchaseOrderLine extends BaseEnterpriseModel
{
    protected $table = 'purchase_order_lines';

    protected static function booted(): void
    {
        self::saving(function (PurchaseOrderLine $line): void {
            if ($line->line_net_amount === null) {
                $line->line_net_amount = $line->getAttribute('subtotal') ?? round((float) $line->quantity_ordered * (float) $line->unit_price, 2);
            }
            if ($line->line_tax_amount === null) {
                $rate = (float) ($line->tax_rate ?? 0);
                $line->line_tax_amount = $line->getAttribute('tax_amount') ?? round((float) $line->line_net_amount * ($rate / 100), 2);
            }
            if ($line->line_total_amount === null) {
                $line->line_total_amount = $line->getAttribute('total') ?? round((float) $line->line_net_amount + (float) $line->line_tax_amount, 2);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity_ordered' => 'decimal:4',
            'quantity_received' => 'decimal:4',
            'quantity_invoiced' => 'decimal:4',
            'quantity_cancelled' => 'decimal:4',
            'quantity_returned' => 'decimal:4',
            'unit_weight' => 'decimal:4',
            'list_unit_price' => 'decimal:4',
            'expected_delivery_date' => 'date',
            'unit_price' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'line_net_amount' => 'decimal:2',
            'line_tax_amount' => 'decimal:2',
            'line_total_amount' => 'decimal:2',
            'is_closed' => 'boolean',
            'uom_conversion_factor' => 'decimal:6',
            'quantity_base_ordered' => 'decimal:4',
            'quantity_base_received' => 'decimal:4',
            'quantity_confirmed' => 'decimal:4',
            'discount_cascade' => 'array',
            'discount_percent_effective' => 'decimal:4',
            'confirmed_delivery_date' => 'date',
            'promised_delivery_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * @return BelongsTo<SupplierCatalog, $this>
     */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(SupplierCatalog::class, 'supplier_catalog_id');
    }
}
