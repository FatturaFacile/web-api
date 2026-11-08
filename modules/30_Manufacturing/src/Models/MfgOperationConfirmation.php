<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A confirmation of a production order operation: actual setup, run and labour minutes, downtime, good and scrapped
 * quantities, and the labour, overhead (or subcontracting) cost absorbed into WIP with its journal entry.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $confirmation_number
 * @property string $production_order_id
 * @property string $production_order_operation_id
 * @property string|null $shop_floor_execution_id
 * @property string|null $subcontract_order_id
 * @property string $setup_minutes
 * @property string $run_minutes
 * @property string $labor_minutes
 * @property string $downtime_minutes
 * @property string $good_quantity
 * @property string $scrap_quantity
 * @property string|null $scrap_reason
 * @property bool $is_final
 * @property string $labor_cost
 * @property string $overhead_cost
 * @property string $subcontract_cost
 * @property Carbon $posting_date
 * @property string|null $journal_entry_id
 * @property string|null $confirmed_by
 * @property Carbon $confirmed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgOperationConfirmation extends BaseEnterpriseModel
{
    protected $table = 'mfg_operation_confirmations';

    protected function casts(): array
    {
        return [
            'setup_minutes' => 'decimal:2',
            'run_minutes' => 'decimal:2',
            'labor_minutes' => 'decimal:2',
            'downtime_minutes' => 'decimal:2',
            'good_quantity' => 'decimal:4',
            'scrap_quantity' => 'decimal:4',
            'is_final' => 'boolean',
            'labor_cost' => 'decimal:4',
            'overhead_cost' => 'decimal:4',
            'subcontract_cost' => 'decimal:4',
            'posting_date' => 'date',
            'confirmed_at' => 'datetime',
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
}
