<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The part of a transfer line that did not arrive in good condition: shortage (missing) and damage (arrived broken). Its
 * stock stays on the transit bin until a manager decides: write-off, return to origin or carrier claim.
 */
final class TransferDiscrepancy extends BaseEnterpriseModel
{
    protected $table = 'transfer_discrepancies';

    protected function casts(): array
    {
        return [
            'discrepancy_qty' => 'decimal:4',
            'shortage_qty' => 'decimal:4',
            'damage_qty' => 'decimal:4',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TransferOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(TransferOrder::class, 'transfer_order_id');
    }

    /**
     * @return BelongsTo<TransferOrderLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(TransferOrderLine::class, 'transfer_order_line_id');
    }
}
