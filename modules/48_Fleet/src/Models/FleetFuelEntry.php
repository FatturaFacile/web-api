<?php

declare(strict_types=1);

namespace Modules\Fleet\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\HumanResources\Models\Employee;

/**
 * A refuel or a charging session. Distance, consumption per 100 km and cost per km are computed by the service from the
 * previous odometer reading of the vehicle.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $vehicle_id
 * @property string|null $employee_id
 * @property Carbon $entry_date
 * @property string $energy_unit
 * @property string $quantity
 * @property string|null $unit_price
 * @property string $total_cost
 * @property string $currency
 * @property int $odometer_km
 * @property bool $is_full_tank
 * @property string|null $station
 * @property int|null $distance_km
 * @property string|null $consumption_per_100km
 * @property string|null $cost_per_km
 * @property string|null $notes
 */
final class FleetFuelEntry extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Units = ['liter', 'kwh', 'kg'];

    protected $table = 'fleet_fuel_entries';

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:4',
            'total_cost' => 'decimal:2',
            'odometer_km' => 'integer',
            'is_full_tank' => 'boolean',
            'distance_km' => 'integer',
            'consumption_per_100km' => 'decimal:3',
            'cost_per_km' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<FleetVehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(FleetVehicle::class, 'vehicle_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
