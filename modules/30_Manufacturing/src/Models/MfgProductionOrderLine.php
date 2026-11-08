<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Manufacturing\Support\Money;

/**
 * A component requirement of a production order, exploded from the effective BOM revision at release: required
 * quantity, reserved (allocated) and issued (consumed) quantity, standard unit cost and the actual cost issued.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $production_order_id
 * @property string $mfg_item_id
 * @property string|null $bom_component_id
 * @property string|null $operation_id
 * @property int|null $operation_sequence
 * @property string $issue_method
 * @property string $quantity_per_unit
 * @property string $required_quantity
 * @property string $allocated_quantity
 * @property string $consumed_quantity
 * @property string $scrapped_quantity
 * @property string $uom
 * @property string $unit_cost
 * @property string $total_cost
 * @property string $issued_cost
 * @property string|null $reservation_id
 * @property string|null $warehouse_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MfgItem|null $mfgItem
 */
final class MfgProductionOrderLine extends BaseEnterpriseModel
{
    protected $table = 'mfg_production_order_lines';

    protected function casts(): array
    {
        return [
            'required_quantity' => 'decimal:4',
            'allocated_quantity' => 'decimal:4',
            'consumed_quantity' => 'decimal:4',
            'scrapped_quantity' => 'decimal:4',
            'quantity_per_unit' => 'decimal:6',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:4',
            'issued_cost' => 'decimal:4',
            'operation_sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MfgProductionOrder, $this>
     */
    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(MfgProductionOrder::class, 'production_order_id');
    }

    /**
     * @return BelongsTo<MfgItem, $this>
     */
    public function mfgItem(): BelongsTo
    {
        return $this->belongsTo(MfgItem::class, 'mfg_item_id');
    }

    /**
     * @return BelongsTo<MfgOperation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(MfgOperation::class, 'operation_id');
    }

    /**
     * Quantity still to be issued.
     */
    public function openQuantity(): string
    {
        return Money::max('0', Money::sub($this->required_quantity, $this->consumed_quantity));
    }

    /**
     * Whether the component is consumed automatically (backflush) rather than issued by hand.
     */
    public function isBackflushed(): bool
    {
        return $this->issue_method === 'backflush';
    }
}
