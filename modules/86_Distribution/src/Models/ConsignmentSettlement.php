<?php

declare(strict_types=1);

namespace Modules\Distribution\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Distribution\Domain\Enums\SettlementStatus;
use Modules\Inventory\Models\Warehouse;

/**
 * Consumption reported at a consignment warehouse (goods held at a partner, or partner goods held by us): the stock was
 * issued and the settlement waits for invoicing.
 *
 * @property string $settlement_number
 * @property string $warehouse_id
 * @property string $direction
 * @property SettlementStatus $status
 * @property string|null $reference
 * @property Carbon $settled_at
 * @property string $total_quantity
 * @property string $total_value
 * @property string|null $notes
 * @property string|null $invoice_document_id
 */
final class ConsignmentSettlement extends BaseEnterpriseModel
{
    protected $table = 'distribution_consignment_settlements';

    protected function casts(): array
    {
        return [
            'status' => SettlementStatus::class,
            'settled_at' => 'date',
            'total_quantity' => 'decimal:4',
            'total_value' => 'decimal:2',
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
     * @return HasMany<ConsignmentSettlementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ConsignmentSettlementLine::class, 'settlement_id');
    }
}
