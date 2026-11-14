<?php

declare(strict_types=1);

namespace Modules\Fleet\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A company vehicle. The odometer only ever grows: it follows the readings of fuel entries, services and assignments.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $license_plate
 * @property string|null $vin
 * @property string $make
 * @property string $model
 * @property int|null $model_year
 * @property string $vehicle_type
 * @property string $fuel_type
 * @property string $status
 * @property int $initial_odometer_km
 * @property int $odometer_km
 * @property string $ownership
 * @property Carbon|null $acquired_on
 * @property Carbon|null $contract_ends_on
 * @property string|null $purchase_value
 * @property string $currency
 * @property string|null $asset_id
 * @property string|null $notes
 */
final class FleetVehicle extends BaseEnterpriseModel
{
    public const string Active = 'active';

    public const string Maintenance = 'maintenance';

    public const string Sold = 'sold';

    public const string Disposed = 'disposed';

    /** @var list<string> */
    public const array Statuses = [self::Active, self::Maintenance, self::Sold, self::Disposed];

    /** @var list<string> */
    public const array Types = ['car', 'van', 'truck', 'motorcycle', 'bus', 'trailer', 'other'];

    /** @var list<string> */
    public const array FuelTypes = ['petrol', 'diesel', 'electric', 'hybrid', 'plugin_hybrid', 'lpg', 'cng', 'hydrogen', 'other'];

    /** @var list<string> */
    public const array Ownerships = ['owned', 'leased', 'rented'];

    protected $table = 'fleet_vehicles';

    protected function casts(): array
    {
        return [
            'model_year' => 'integer',
            'initial_odometer_km' => 'integer',
            'odometer_km' => 'integer',
            'acquired_on' => 'date',
            'contract_ends_on' => 'date',
            'purchase_value' => 'decimal:2',
        ];
    }

    public function isRetired(): bool
    {
        return in_array($this->status, [self::Sold, self::Disposed], true);
    }

    /**
     * @return HasMany<FleetVehicleAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(FleetVehicleAssignment::class, 'vehicle_id');
    }
}
