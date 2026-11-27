<?php

declare(strict_types=1);

namespace Modules\Distribution\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Distribution\Domain\Enums\ProposalStatus;
use Modules\Distribution\Domain\Enums\ProposalType;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;

/**
 * A replenishment or lateral balancing proposal with the full calculation breakdown that triggered it.
 *
 * @property ProposalType $proposal_type
 * @property ProposalStatus $status
 * @property string $warehouse_id
 * @property string $stock_item_id
 * @property string|null $source_warehouse_id
 * @property string|null $rule_id
 * @property string $suggested_quantity
 * @property string $original_quantity
 * @property string $shortfall_quantity
 * @property string $source_available_quantity
 * @property Carbon|null $projected_stockout_date
 * @property array<string, mixed>|null $calculation
 * @property string|null $transfer_order_id
 * @property Carbon|null $generated_at
 */
final class ReplenishmentProposal extends BaseEnterpriseModel
{
    protected $table = 'distribution_replenishment_proposals';

    protected function casts(): array
    {
        return [
            'proposal_type' => ProposalType::class,
            'status' => ProposalStatus::class,
            'suggested_quantity' => 'decimal:4',
            'original_quantity' => 'decimal:4',
            'shortfall_quantity' => 'decimal:4',
            'source_available_quantity' => 'decimal:4',
            'projected_stockout_date' => 'date',
            'calculation' => 'array',
            'generated_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }
}
