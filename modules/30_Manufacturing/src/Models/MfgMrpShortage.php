<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The netting of an item in an MRP run when it is short: gross requirement, projected available stock, scheduled
 * receipts, safety stock and the net shortage covered by a planned order.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $mrp_run_id
 * @property string $mfg_item_id
 * @property Carbon $required_date
 * @property string $gross_requirement
 * @property string $available_inventory
 * @property string $scheduled_receipts
 * @property string $safety_stock
 * @property string $net_shortage
 * @property string $recommended_action
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgMrpShortage extends BaseEnterpriseModel
{
    protected $table = 'mfg_mrp_shortages';

    protected function casts(): array
    {
        return [
            'required_date' => 'date',
            'gross_requirement' => 'decimal:4',
            'available_inventory' => 'decimal:4',
            'scheduled_receipts' => 'decimal:4',
            'safety_stock' => 'decimal:4',
            'net_shortage' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<MfgMrpRun, $this>
     */
    public function mrpRun(): BelongsTo
    {
        return $this->belongsTo(MfgMrpRun::class, 'mrp_run_id');
    }

    /**
     * @return BelongsTo<MfgItem, $this>
     */
    public function mfgItem(): BelongsTo
    {
        return $this->belongsTo(MfgItem::class, 'mfg_item_id');
    }
}
