<?php

declare(strict_types=1);

namespace Modules\Plm\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Manufacturing\Models\MfgItem;

/**
 * An item whose revision the change order creates on implementation.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $change_order_id
 * @property string $mfg_item_id
 * @property string $to_revision
 * @property string|null $change_summary
 */
final class PlmAffectedItem extends BaseEnterpriseModel
{
    protected $table = 'plm_affected_items';

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
}
