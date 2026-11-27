<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A planned and actual input line of a subcontracting order (BOM component or the semi-finished goods of a single operation).
 *
 * @property string $subcontracting_order_id
 * @property string $component_product_id
 * @property string $unit_of_measure
 * @property string $required_qty_per_unit
 * @property string $total_planned_qty
 * @property string $dispatched_qty
 * @property string $consumed_qty
 * @property string $scrap_qty
 * @property string $returned_unused_qty
 * @property string|null $lot_id
 * @property string|null $serial_number
 */
final class SubcontractingOrderComponent extends BaseEnterpriseModel
{
    protected $table = 'sct_order_components';

    protected function casts(): array
    {
        return [
            'required_qty_per_unit' => 'decimal:6',
            'total_planned_qty' => 'decimal:4',
            'dispatched_qty' => 'decimal:4',
            'consumed_qty' => 'decimal:4',
            'scrap_qty' => 'decimal:4',
            'returned_unused_qty' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<SubcontractingOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(SubcontractingOrder::class, 'subcontracting_order_id');
    }

    /** Quantity still at the subcontractor: dispatched less consumed, scrapped and returned unused. */
    public function heldQuantity(): string
    {
        $held = bcsub(bcsub(bcsub((string) $this->dispatched_qty, (string) $this->consumed_qty, 4), (string) $this->scrap_qty, 4), (string) $this->returned_unused_qty, 4);

        return bccomp($held, '0', 4) > 0 ? $held : '0.0000';
    }

    /** Quantity still to dispatch against the plan. */
    public function toDispatchQuantity(): string
    {
        $open = bcsub((string) $this->total_planned_qty, (string) $this->dispatched_qty, 4);

        return bccomp($open, '0', 4) > 0 ? $open : '0.0000';
    }
}
