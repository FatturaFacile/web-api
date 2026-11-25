<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\ExpenseManagement\Domain\Enums\CalculationSource;

/**
 * @property string $expense_line_id
 * @property string $origin_address
 * @property string $destination_address
 * @property list<string>|null $waypoint_addresses
 * @property string $distance_km
 * @property bool $is_round_trip
 * @property string|null $vehicle_id
 * @property string|null $vehicle_make_model
 * @property string $vehicle_class_code
 * @property string $rate_per_km
 * @property string $calculated_amount
 * @property CalculationSource $calculation_source
 * @property string|null $rate_period
 * @property Carbon|null $rate_effective_date
 */
final class MileageClaim extends BaseEnterpriseModel
{
    protected $table = 'exm_mileage_claims';

    protected function casts(): array
    {
        return [
            'waypoint_addresses' => 'array',
            'distance_km' => 'decimal:2',
            'is_round_trip' => 'boolean',
            'rate_per_km' => 'decimal:4',
            'calculated_amount' => 'decimal:2',
            'calculation_source' => CalculationSource::class,
            'rate_effective_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<ExpenseLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(ExpenseLine::class, 'expense_line_id');
    }
}
