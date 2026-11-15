<?php

declare(strict_types=1);

namespace Modules\Transportation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pickup or delivery stop of a trip, in the order the vehicle visits it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $trip_id
 * @property int $sequence
 * @property string $stop_type
 * @property string|null $transport_order_id
 * @property string $location
 * @property string|null $city
 * @property Carbon|null $planned_arrival_at
 * @property Carbon|null $actual_arrival_at
 * @property Carbon|null $actual_departure_at
 * @property string $status
 */
final class TmsTripStop extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array StopTypes = ['pickup', 'delivery'];

    /** @var list<string> */
    public const array Statuses = ['pending', 'completed', 'skipped'];

    public const string Pending = 'pending';

    public const string Completed = 'completed';

    public const string Skipped = 'skipped';

    protected $table = 'tms_trip_stops';

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'planned_arrival_at' => 'datetime',
            'actual_arrival_at' => 'datetime',
            'actual_departure_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TmsTrip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(TmsTrip::class, 'trip_id');
    }

    /**
     * @return BelongsTo<TmsTransportOrder, $this>
     */
    public function transportOrder(): BelongsTo
    {
        return $this->belongsTo(TmsTransportOrder::class, 'transport_order_id');
    }
}
