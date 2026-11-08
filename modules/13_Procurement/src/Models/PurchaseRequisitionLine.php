<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An item requested in a purchase requisition, with its estimated price and an optional suggested supplier.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $purchase_requisition_id
 * @property int $line_number
 * @property string|null $supplier_catalog_id
 * @property string $description
 * @property string $quantity_requested
 * @property string $uom
 * @property string $estimated_unit_price
 * @property string $estimated_total_price
 * @property string|null $suggested_supplier_id
 * @property SupplierCatalog|null $catalog
 */
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

    /**
     * @return BelongsTo<PurchaseRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    /**
     * @return BelongsTo<SupplierCatalog, $this>
     */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(SupplierCatalog::class, 'supplier_catalog_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function suggestedSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'suggested_supplier_id');
    }
}
