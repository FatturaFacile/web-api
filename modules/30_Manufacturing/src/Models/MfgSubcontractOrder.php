<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A subcontracted operation of a production order: components sent to the subcontractor's location, the processed
 * quantity received back at the agreed unit cost (sent, partially_received, received, cancelled).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $subcontract_number
 * @property string $production_order_id
 * @property string $production_order_operation_id
 * @property string $subcontractor_contact_id
 * @property string $source_warehouse_id
 * @property string $subcontract_warehouse_id
 * @property string $status
 * @property string $quantity
 * @property string $received_quantity
 * @property string $scrapped_quantity
 * @property string $unit_cost
 * @property string $total_cost
 * @property Carbon|null $sent_at
 * @property Carbon|null $received_at
 * @property string|null $notes
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, MfgSubcontractOrderLine> $lines
 */
final class MfgSubcontractOrder extends BaseEnterpriseModel
{
    protected $table = 'mfg_subcontract_orders';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'received_quantity' => 'decimal:4',
            'scrapped_quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:4',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
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
     * @return BelongsTo<MfgProductionOrderOperation, $this>
     */
    public function productionOrderOperation(): BelongsTo
    {
        return $this->belongsTo(MfgProductionOrderOperation::class, 'production_order_operation_id');
    }

    /**
     * @return HasMany<MfgSubcontractOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(MfgSubcontractOrderLine::class, 'subcontract_order_id');
    }

    /**
     * Whether the subcontractor still holds quantity to process.
     */
    public function isOpen(): bool
    {
        return in_array($this->status, ['sent', 'partially_received'], true);
    }
}
