<?php

declare(strict_types=1);

namespace Modules\Fleet\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\HumanResources\Models\Employee;

/**
 * A vehicle handed to a driver (an employee, or an external driver by label) for a period, with the odometer at handover
 * and at return. An open assignment has no end date.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $vehicle_id
 * @property string|null $employee_id
 * @property string|null $driver_label
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property int $start_odometer_km
 * @property int|null $end_odometer_km
 * @property string|null $notes
 */
final class FleetVehicleAssignment extends BaseEnterpriseModel
{
    protected $table = 'fleet_vehicle_assignments';

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'start_odometer_km' => 'integer',
            'end_odometer_km' => 'integer',
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
