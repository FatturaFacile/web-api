<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PurchaseOrderLine extends BaseEnterpriseModel
{
    protected $table = 'purchase_order_lines';

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity_ordered' => 'decimal:4',
            'quantity_received' => 'decimal:4',
            'quantity_invoiced' => 'decimal:4',
            'quantity_cancelled' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'line_net_amount' => 'decimal:2',
            'line_tax_amount' => 'decimal:2',
            'line_total_amount' => 'decimal:2',
            'is_closed' => 'boolean',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(SupplierCatalog::class, 'supplier_catalog_id');
    }
}
