<?php

declare(strict_types=1);

namespace Modules\Returns\Models;

use App\Models\Product;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Line item belonging to a CustomerReturn (Mod 232).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $customer_return_id
 * @property string|null $invoice_item_id
 * @property string|null $delivery_note_item_id
 * @property string|null $sales_order_item_id
 * @property string $product_id
 * @property string $description
 * @property string $quantity
 * @property string $quantity_received
 * @property string $quantity_inspected
 * @property string $unit_price
 * @property string $tax_rate
 * @property string|null $serial_number
 * @property string|null $lot_number
 * @property string|null $reason_code
 * @property string|null $grade
 * @property string|null $disposition
 * @property array<int, string>|null $photo_urls
 * @property string|null $inspection_notes
 * @property string|null $stock_movement_id
 */
final class CustomerReturnLine extends BaseEnterpriseModel
{
    protected $table = 'customer_return_lines';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'quantity_received' => 'decimal:4',
            'quantity_inspected' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'photo_urls' => 'array',
        ];
    }

    /**
     * @return BelongsTo<CustomerReturn, $this>
     */
    public function customerReturn(): BelongsTo
    {
        return $this->belongsTo(CustomerReturn::class, 'customer_return_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
