<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The manufacturing side of a stock movement of a production order: component issue or backflush into WIP, output
 * receipt out of WIP, components sent to or consumed at a subcontractor. Points to the inventory movement and to the
 * journal entry that valued it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $production_order_id
 * @property string|null $production_order_line_id
 * @property string|null $subcontract_order_id
 * @property string $movement_type
 * @property string $mfg_item_id
 * @property string|null $warehouse_id
 * @property string $quantity
 * @property string $unit_cost
 * @property string $total_cost
 * @property string|null $stock_movement_id
 * @property string|null $lot_id
 * @property list<string>|null $serial_number_ids
 * @property Carbon $posting_date
 * @property string|null $journal_entry_id
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgMaterialMovement extends BaseEnterpriseModel
{
    /** Component issued by hand. */
    public const string Issue = 'issue';

    /** Component consumed automatically by an operation confirmation or a receipt. */
    public const string Backflush = 'backflush';

    /** Output received into stock. */
    public const string Receipt = 'receipt';

    /** Component transferred to the subcontractor's location. */
    public const string SubcontractSend = 'subcontract_send';

    /** Component consumed at the subcontractor. */
    public const string SubcontractConsume = 'subcontract_consume';

    /** Component returned from the subcontractor's location. */
    public const string SubcontractReturn = 'subcontract_return';

    protected $table = 'mfg_material_movements';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:6',
            'total_cost' => 'decimal:4',
            'serial_number_ids' => 'array',
            'posting_date' => 'date',
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
}
