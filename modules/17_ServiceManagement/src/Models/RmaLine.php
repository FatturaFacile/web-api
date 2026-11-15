<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Diagnosis / estimate line of an RMA (part or labour).
 *
 * @property string $id
 * @property string $rma_order_id
 * @property string $line_type
 * @property string|null $product_id
 * @property string $description
 * @property string $qty
 * @property string $unit_cost
 * @property string $unit_price
 * @property string $charged_to
 * @property string|null $defective_part_serial
 * @property string|null $supplier_id
 * @property string|null $replacement_part_serial
 */
final class RmaLine extends BaseEnterpriseModel
{
    public const TYPE_PART = 'part';

    public const TYPE_LABOUR = 'labour';

    public const CHARGED_TO_CUSTOMER = 'customer';

    public const CHARGED_TO_WARRANTY_PROVISION = 'warranty_provision';

    protected $table = 'esm_rma_lines';

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'unit_cost' => 'decimal:2',
            'unit_price' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<DepotRepairRmaOrder, $this>
     */
    public function rmaOrder(): BelongsTo
    {
        return $this->belongsTo(DepotRepairRmaOrder::class, 'rma_order_id');
    }

    public function totalPrice(): string
    {
        return number_format(round((float) $this->qty * (float) $this->unit_price, 2), 2, '.', '');
    }

    public function totalCost(): string
    {
        return number_format(round((float) $this->qty * (float) $this->unit_cost, 2), 2, '.', '');
    }
}
