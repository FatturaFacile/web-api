<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a sales return: what is sent back of a delivery note line or an invoice line, at the price it was sold, how
 * much was received, its condition and whether it goes back into stock.
 *
 * @property string $id
 * @property string $sales_return_id
 * @property string|null $delivery_note_item_id
 * @property string|null $invoice_item_id
 * @property string|null $sales_order_item_id
 * @property string|null $product_id
 * @property string $description
 * @property string $quantity
 * @property string $quantity_received
 * @property string $unit_price
 * @property string $tax_rate
 * @property string $discount_percentage
 * @property string|null $condition
 * @property bool $restock
 */
class SalesReturnItem extends Model
{
    use BelongsToTenant, HasUuids;

    /**
     * Condition of returned goods: resellable goods can go back into stock, damaged ones are credited but not restocked.
     *
     * @var list<string>
     */
    public const array Conditions = ['resellable', 'damaged'];

    protected $fillable = [
        'sales_return_id',
        'delivery_note_item_id',
        'invoice_item_id',
        'sales_order_item_id',
        'product_id',
        'description',
        'quantity',
        'quantity_received',
        'unit_price',
        'tax_rate',
        'discount_percentage',
        'condition',
        'restock',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'quantity_received' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'discount_percentage' => 'decimal:2',
            'restock' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SalesReturn, $this>
     */
    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
