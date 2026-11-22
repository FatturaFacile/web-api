<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Manufacturing\Models\MfgItem;
use Modules\MrpPlanning\Domain\Enums\ProposalStatus;
use Modules\MrpPlanning\Domain\Enums\ProposalType;

/**
 * An action proposed by an MRP run: a planned order, or advice on an open order. Pegging lists the demand that caused it
 * (MPS line Mod 062, sales order line Mod 037, safety stock Mod 051, or the parent proposal whose explosion created it).
 *
 * @property string $mrp_run_id
 * @property string $mfg_item_id
 * @property string|null $product_id
 * @property string|null $bom_version_id
 * @property string|null $work_center_id
 * @property int $low_level_code
 * @property ProposalType $proposal_type
 * @property ProposalStatus $status
 * @property string $gross_requirement_qty
 * @property string $projected_on_hand_qty
 * @property string $net_requirement_qty
 * @property string $required_qty
 * @property string $suggested_order_qty
 * @property Carbon $need_by_date
 * @property Carbon $release_order_by_date
 * @property Carbon|null $planned_start_at
 * @property Carbon|null $planned_end_at
 * @property list<array{type: string, id: string, quantity: string}>|null $pegged_demand_source_ids
 * @property list<string>|null $exception_messages
 * @property string|null $supply_reference
 * @property string|null $firm_reference_type
 * @property string|null $firm_reference_id
 * @property Carbon|null $firmed_at
 * @property Carbon|null $released_at
 * @property-read MfgItem|null $item
 */
final class MrpActionProposal extends BaseEnterpriseModel
{
    protected $table = 'mrp_action_proposals';

    protected function casts(): array
    {
        return [
            'proposal_type' => ProposalType::class,
            'status' => ProposalStatus::class,
            'low_level_code' => 'integer',
            'gross_requirement_qty' => 'decimal:4',
            'projected_on_hand_qty' => 'decimal:4',
            'net_requirement_qty' => 'decimal:4',
            'required_qty' => 'decimal:4',
            'suggested_order_qty' => 'decimal:4',
            'need_by_date' => 'date',
            'release_order_by_date' => 'date',
            'planned_start_at' => 'datetime',
            'planned_end_at' => 'datetime',
            'pegged_demand_source_ids' => 'array',
            'exception_messages' => 'array',
            'firmed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MfgItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(MfgItem::class, 'mfg_item_id');
    }

    /**
     * @return HasMany<MrpScheduleBlock, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(MrpScheduleBlock::class, 'proposal_id');
    }
}
