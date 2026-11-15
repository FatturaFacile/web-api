<?php

declare(strict_types=1);

namespace Modules\Transportation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A planned run of a vehicle or carrier that consolidates transport orders into ordered pickup and delivery stops.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $number
 * @property string $status
 * @property string|null $carrier_id
 * @property string|null $vehicle_id
 * @property Carbon|null $planned_start_at
 * @property Carbon|null $planned_end_at
 * @property Carbon|null $actual_start_at
 * @property Carbon|null $actual_end_at
 * @property string|null $capacity_weight_kg
 * @property string|null $capacity_volume_cbm
 * @property string|null $planned_distance_km
 * @property string|null $actual_distance_km
 * @property string|null $actual_cost
 * @property string $currency
 * @property string $total_weight_kg
 * @property string $total_volume_cbm
 * @property string|null $utilization_percent
 * @property string|null $notes
 */
final class TmsTrip extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Statuses = ['planned', 'dispatched', 'in_progress', 'completed', 'cancelled'];

    public const string Planned = 'planned';

    public const string Dispatched = 'dispatched';

    public const string InProgress = 'in_progress';

    public const string Completed = 'completed';

    public const string Cancelled = 'cancelled';

    protected $table = 'tms_trips';

    protected function casts(): array
    {
        return [
            'planned_start_at' => 'datetime',
            'planned_end_at' => 'datetime',
            'actual_start_at' => 'datetime',
            'actual_end_at' => 'datetime',
            'capacity_weight_kg' => 'decimal:3',
            'capacity_volume_cbm' => 'decimal:3',
            'planned_distance_km' => 'decimal:2',
            'actual_distance_km' => 'decimal:2',
            'actual_cost' => 'decimal:2',
            'total_weight_kg' => 'decimal:3',
            'total_volume_cbm' => 'decimal:3',
            'utilization_percent' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<TmsCarrier, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(TmsCarrier::class, 'carrier_id');
    }

    /**
     * @return HasMany<TmsTripStop, $this>
     */
    public function stops(): HasMany
    {
        return $this->hasMany(TmsTripStop::class, 'trip_id')->orderBy('sequence');
    }

    /**
     * @return HasMany<TmsTransportOrder, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(TmsTransportOrder::class, 'trip_id');
    }
}
