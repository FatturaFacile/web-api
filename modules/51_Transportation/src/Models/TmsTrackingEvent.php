<?php

declare(strict_types=1);

namespace Modules\Transportation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A milestone of a transport order (picked up, at hub, delivered, exception, delay). Events drive the status of the order.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $transport_order_id
 * @property string $event_type
 * @property string|null $location
 * @property Carbon $occurred_at
 * @property string|null $notes
 */
final class TmsTrackingEvent extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array EventTypes = ['picked_up', 'in_transit', 'at_hub', 'out_for_delivery', 'delivered', 'exception', 'delay'];

    protected $table = 'tms_tracking_events';

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TmsTransportOrder, $this>
     */
    public function transportOrder(): BelongsTo
    {
        return $this->belongsTo(TmsTransportOrder::class, 'transport_order_id');
    }
}
