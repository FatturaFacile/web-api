<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Manufacturing\Support\Money;

/**
 * A component sent to a subcontractor for a subcontract order: quantity sent, consumed by received output, returned.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $subcontract_order_id
 * @property string $production_order_line_id
 * @property string $mfg_item_id
 * @property string $quantity_sent
 * @property string $quantity_consumed
 * @property string $quantity_returned
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgSubcontractOrderLine extends BaseEnterpriseModel
{
    protected $table = 'mfg_subcontract_order_lines';

    protected function casts(): array
    {
        return [
            'quantity_sent' => 'decimal:4',
            'quantity_consumed' => 'decimal:4',
            'quantity_returned' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<MfgProductionOrderLine, $this>
     */
    public function productionOrderLine(): BelongsTo
    {
        return $this->belongsTo(MfgProductionOrderLine::class, 'production_order_line_id');
    }

    /**
     * Quantity still at the subcontractor.
     */
    public function quantityHeld(): string
    {
        return Money::sub(Money::sub($this->quantity_sent, $this->quantity_consumed), $this->quantity_returned);
    }
}
