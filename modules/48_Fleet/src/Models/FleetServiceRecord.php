<?php

declare(strict_types=1);

namespace Modules\Fleet\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A maintenance, repair or other service done on a vehicle, optionally completing a scheduled service.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $vehicle_id
 * @property string|null $scheduled_service_id
 * @property string $service_type
 * @property Carbon $service_date
 * @property int $odometer_km
 * @property string|null $vendor
 * @property string $description
 * @property string|null $reference
 * @property string $cost
 * @property string $currency
 * @property string|null $notes
 */
final class FleetServiceRecord extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Types = ['maintenance', 'repair', 'tyres', 'inspection', 'cleaning', 'other'];

    protected $table = 'fleet_service_records';

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'odometer_km' => 'integer',
            'cost' => 'decimal:2',
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
