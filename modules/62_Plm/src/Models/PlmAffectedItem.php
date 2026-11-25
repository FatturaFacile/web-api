<?php

declare(strict_types=1);

namespace Modules\Plm\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Manufacturing\Models\MfgItem;

/**
 * An item whose revision the change order creates on implementation, with optional per-item
 * stock disposition and cutover strategy overrides.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $change_order_id
 * @property string $mfg_item_id
 * @property string $to_revision
 * @property string|null $change_summary
 * @property string|null $from_revision_id
 * @property string|null $target_revision_id
 * @property string|null $disposition_rule
 * @property string $cutover_mode
 * @property Carbon|null $effective_date
 * @property string|null $lot_serial_cutover
 * @property int $quarantined_lots_count
 * @property int $mes_drawings_obsoleted_count
 */
final class PlmAffectedItem extends BaseEnterpriseModel
{
    protected $table = 'plm_affected_items';

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'quarantined_lots_count' => 'integer',
            'mes_drawings_obsoleted_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PlmChangeOrder, $this>
     */
    public function changeOrder(): BelongsTo
    {
        return $this->belongsTo(PlmChangeOrder::class, 'change_order_id');
    }

    /**
     * @return BelongsTo<MfgItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(MfgItem::class, 'mfg_item_id');
    }

    /**
     * @return BelongsTo<PlmItemRevision, $this>
     */
    public function fromRevision(): BelongsTo
    {
        return $this->belongsTo(PlmItemRevision::class, 'from_revision_id');
    }

    /**
     * @return BelongsTo<PlmItemRevision, $this>
     */
    public function targetRevision(): BelongsTo
    {
        return $this->belongsTo(PlmItemRevision::class, 'target_revision_id');
    }
}
