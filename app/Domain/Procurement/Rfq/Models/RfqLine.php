<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Rfq\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisitionLine;
use Modules\Procurement\Models\Supplier;

/**
 * Line item requested inside a RequestForQuotation.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $rfq_id
 * @property int $line_number
 * @property string|null $purchase_requisition_line_id
 * @property string|null $item_id
 * @property string|null $custom_item_code
 * @property string|null $internal_sku
 * @property string $description
 * @property string|null $technical_specifications
 * @property string $quantity
 * @property string $uom
 * @property string|null $target_unit_price
 * @property Carbon|null $required_by_date
 * @property string|null $awarded_supplier_id
 * @property string|null $awarded_bid_line_id
 * @property string|null $generated_purchase_order_id
 * @property-read RequestForQuotation|null $rfq
 * @property-read PurchaseRequisitionLine|null $sourceRequisitionLine
 * @property-read Supplier|null $awardedSupplier
 * @property-read SupplierBidLine|null $awardedBidLine
 * @property-read PurchaseOrder|null $generatedPurchaseOrder
 */
final class RfqLine extends BaseEnterpriseModel
{
    protected $table = 'procurement_rfq_lines';

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:4',
            'target_unit_price' => 'decimal:4',
            'required_by_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<RequestForQuotation, $this>
     */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(RequestForQuotation::class, 'rfq_id');
    }

    /**
     * @return BelongsTo<PurchaseRequisitionLine, $this>
     */
    public function sourceRequisitionLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisitionLine::class, 'purchase_requisition_line_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function awardedSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'awarded_supplier_id');
    }

    /**
     * @return BelongsTo<SupplierBidLine, $this>
     */
    public function awardedBidLine(): BelongsTo
    {
        return $this->belongsTo(SupplierBidLine::class, 'awarded_bid_line_id');
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function generatedPurchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'generated_purchase_order_id');
    }
}
