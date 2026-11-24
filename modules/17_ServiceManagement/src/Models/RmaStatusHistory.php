<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only status transition log of an RMA.
 *
 * @property string $id
 * @property string $rma_order_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $actor_user_id
 * @property string $actor_type
 * @property string|null $note
 * @property Carbon|null $created_at
 */
final class RmaStatusHistory extends BaseEnterpriseModel
{
    public const ACTOR_USER = 'user';

    public const ACTOR_CUSTOMER = 'customer';

    public const ACTOR_SYSTEM = 'system';

    protected $table = 'esm_rma_status_history';

    /**
     * @return BelongsTo<DepotRepairRmaOrder, $this>
     */
    public function rmaOrder(): BelongsTo
    {
        return $this->belongsTo(DepotRepairRmaOrder::class, 'rma_order_id');
    }
}
