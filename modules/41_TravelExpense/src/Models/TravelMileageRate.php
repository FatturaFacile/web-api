<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The reimbursement per kilometre of a company for a jurisdiction, year and vehicle type (ACI-style tables in Italy,
 * the flat Swiss rate per kilometre).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $jurisdiction
 * @property int $year
 * @property string $vehicle_type
 * @property string $currency
 * @property string $rate_per_km
 * @property string|null $notes
 */
final class TravelMileageRate extends BaseEnterpriseModel
{
    public const array VehicleTypes = ['car', 'motorcycle', 'bicycle'];

    protected $table = 'travel_mileage_rates';

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'rate_per_km' => 'decimal:4',
        ];
    }
}
