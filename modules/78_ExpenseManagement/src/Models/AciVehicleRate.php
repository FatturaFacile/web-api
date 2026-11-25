<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Support\Carbon;

/**
 * A cost-per-km rate of a vehicle for a period: the ACI tables (IT_ACI_TABLE, semi-annual) or the TCS ones (CH_TCS).
 *
 * @property string $source
 * @property string $vehicle_code
 * @property string $make_model
 * @property string|null $fuel
 * @property string|null $vehicle_class
 * @property int $annual_km_bracket
 * @property string $rate_per_km
 * @property string $currency
 * @property string $rate_period
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 */
final class AciVehicleRate extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'exm_aci_vehicle_rates';

    protected function casts(): array
    {
        return [
            'annual_km_bracket' => 'integer',
            'rate_per_km' => 'decimal:4',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }
}
