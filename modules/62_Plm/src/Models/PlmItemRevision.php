<?php

declare(strict_types=1);

namespace Modules\Plm\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Manufacturing\Models\MfgItem;

/**
 * A revision of a manufacturing item. One revision per item is released at a time; a released item changes only
 * through a change order, which releases the new revision and makes the previous one obsolete.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $mfg_item_id
 * @property string $revision
 * @property string $status
 * @property string|null $change_summary
 * @property string|null $change_order_id
 * @property Carbon|null $effective_from
 * @property Carbon|null $released_at
 * @property Carbon|null $obsoleted_at
 */
final class PlmItemRevision extends BaseEnterpriseModel
{
    public const string Draft = 'draft';

    public const string Released = 'released';

    public const string Obsolete = 'obsolete';

    /** @var list<string> */
    public const array Statuses = [self::Draft, self::Released, self::Obsolete];

    protected $table = 'plm_item_revisions';

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'released_at' => 'datetime', 'obsoleted_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<MfgItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(MfgItem::class, 'mfg_item_id');
    }

    /**
     * @return BelongsTo<PlmChangeOrder, $this>
     */
    public function changeOrder(): BelongsTo
    {
        return $this->belongsTo(PlmChangeOrder::class, 'change_order_id');
    }
}
