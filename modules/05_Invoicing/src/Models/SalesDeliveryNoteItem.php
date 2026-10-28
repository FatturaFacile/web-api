<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use App\Models\Product;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalesDeliveryNoteItem extends BaseEnterpriseModel
{
    protected $table = 'delivery_note_items';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'tax_rate' => 'decimal:2',
        ];
    }

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(SalesDeliveryNote::class, 'delivery_note_id');
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class, 'sales_order_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
