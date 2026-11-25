<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\PassengerTransport\Domain\Enums\VehicleCategory;

/**
 * A vehicle of the passenger fleet: NCC car or van, minibus, GT coach, school bus or taxi.
 *
 * @property string $license_plate
 * @property VehicleCategory $vehicle_category
 * @property string $gross_weight_tons
 * @property int $passenger_capacity
 * @property bool $has_digital_tachograph
 * @property bool $psva_subject
 * @property string|null $ncc_authorization_number
 * @property string|null $ncc_issuing_municipality
 * @property int $odometer_km
 * @property bool $active
 */
final class FleetVehicle extends BaseEnterpriseModel
{
    protected $table = 'ptr_fleet_vehicles';

    protected function casts(): array
    {
        return [
            'vehicle_category' => VehicleCategory::class,
            'gross_weight_tons' => 'decimal:2',
            'passenger_capacity' => 'integer',
            'has_digital_tachograph' => 'boolean',
            'psva_subject' => 'boolean',
            'odometer_km' => 'integer',
            'active' => 'boolean',
        ];
    }
}
