<?php

declare(strict_types=1);

namespace Modules\Fleet\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A recurring service due by date, by kilometres or by whichever comes first. Completing a service record moves the due
 * date and kilometres forward by the intervals.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $vehicle_id
 * @property string $name
 * @property string $service_type
 * @property int|null $interval_days
 * @property int|null $interval_km
 * @property Carbon|null $last_service_on
 * @property int|null $last_service_km
 * @property Carbon|null $next_due_on
 * @property int|null $next_due_km
 * @property bool $is_active
 * @property string|null $notes
 */
final class FleetScheduledService extends BaseEnterpriseModel
{
    protected $table = 'fleet_scheduled_services';

    protected function casts(): array
    {
        return [
            'interval_days' => 'integer',
            'interval_km' => 'integer',
            'last_service_on' => 'date',
            'last_service_km' => 'integer',
            'next_due_on' => 'date',
            'next_due_km' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<FleetVehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(FleetVehicle::class, 'vehicle_id');
    }
}
