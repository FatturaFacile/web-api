<?php

declare(strict_types=1);

namespace Modules\Returns\Models;

use App\Models\Product;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product line of a return, tied to the invoice or order line it was sold on. The inspection decides what happens
 * to the received goods (the disposition) and books the stock movement.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $rma_id
 * @property string|null $invoice_item_id
 * @property string|null $sales_order_item_id
 * @property string $product_id
 * @property string $description
 * @property string $quantity
 * @property string $quantity_received
 * @property string $quantity_inspected
 * @property string $unit_price
 * @property string|null $serial_number
 * @property string|null $lot_number
 * @property string|null $reason_code
 * @property string|null $disposition
 * @property string|null $disposition_warehouse_id
 * @property string|null $supplier_id
 * @property string|null $inspection_notes
 * @property string|null $stock_movement_id
 */
final class RmaLine extends BaseEnterpriseModel
{
    public const string Restock = 'restock';

    public const string Scrap = 'scrap';

    public const string Refurbish = 'refurbish';

    public const string ReturnToSupplier = 'return_to_supplier';

    /** @var list<string> */
    public const array Dispositions = [self::Restock, self::Scrap, self::Refurbish, self::ReturnToSupplier];

    protected $table = 'returns_rma_lines';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'quantity_received' => 'decimal:4',
            'quantity_inspected' => 'decimal:4',
            'unit_price' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Rma, $this>
     */
    public function rma(): BelongsTo
    {
        return $this->belongsTo(Rma::class, 'rma_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
