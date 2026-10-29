<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PurchaseRequisitionLine extends BaseEnterpriseModel
{
    protected $table = 'purchase_requisition_lines';

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity_requested' => 'decimal:4',
            'estimated_unit_price' => 'decimal:4',
            'estimated_total_price' => 'decimal:2',
        ];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(SupplierCatalog::class, 'supplier_catalog_id');
    }

    public function suggestedSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'suggested_supplier_id');
    }
}
