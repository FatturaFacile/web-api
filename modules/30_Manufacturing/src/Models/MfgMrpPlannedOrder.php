<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A planned order of an MRP run: make (converted here into a production order) or buy (a purchase suggestion, data
 * only: the Procurement module converts it and records its document through markPurchaseSuggestionConverted).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $planned_order_number
 * @property string $mrp_run_id
 * @property string $mfg_item_id
 * @property string $order_type
 * @property string $quantity
 * @property string $net_requirement
 * @property int $bom_level
 * @property string $uom
 * @property Carbon $start_date
 * @property Carbon $due_date
 * @property string $status
 * @property string|null $bom_id
 * @property string|null $routing_id
 * @property string|null $converted_order_id
 * @property string|null $procurement_document_type
 * @property string|null $procurement_document_id
 * @property string|null $converted_by
 * @property Carbon|null $converted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MfgItem|null $mfgItem
 */
final class MfgMrpPlannedOrder extends BaseEnterpriseModel
{
    protected $table = 'mfg_mrp_planned_orders';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'net_requirement' => 'decimal:4',
            'bom_level' => 'integer',
            'start_date' => 'date',
            'due_date' => 'date',
            'converted_at' => 'datetime',
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

    /**
     * @return BelongsTo<MfgBom, $this>
     */
    public function bom(): BelongsTo
    {
        return $this->belongsTo(MfgBom::class, 'bom_id');
    }

    /**
     * @return BelongsTo<MfgRouting, $this>
     */
    public function routing(): BelongsTo
    {
        return $this->belongsTo(MfgRouting::class, 'routing_id');
    }

    /**
     * @return HasMany<MfgMrpPegging, $this>
     */
    public function peggings(): HasMany
    {
        return $this->hasMany(MfgMrpPegging::class, 'planned_order_id');
    }

    /**
     * Whether the item is to be produced.
     */
    public function isMake(): bool
    {
        return $this->order_type === 'make';
    }

    /**
     * Whether the item is to be purchased.
     */
    public function isBuy(): bool
    {
        return $this->order_type === 'buy';
    }
}
