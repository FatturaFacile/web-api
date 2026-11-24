<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * The per-diem calculation of one calendar day of a trip. The rate is snapshotted in the amounts, so changing the rate
 * table later never alters a day that was already calculated.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $trip_id
 * @property string|null $rate_id
 * @property Carbon $day
 * @property string $hours_away
 * @property string $day_factor
 * @property bool $breakfast_provided
 * @property bool $lunch_provided
 * @property bool $dinner_provided
 * @property bool $lodging_provided
 * @property string $currency
 * @property string $gross_amount
 * @property string $deduction_amount
 * @property string $amount
 * @property string $tax_free_amount
 * @property string $taxable_amount
 */
final class TravelPerDiemDay extends BaseEnterpriseModel
{
    protected $table = 'travel_per_diem_days';

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'hours_away' => 'decimal:2',
            'day_factor' => 'decimal:2',
            'breakfast_provided' => 'boolean',
            'lunch_provided' => 'boolean',
            'dinner_provided' => 'boolean',
            'lodging_provided' => 'boolean',
            'gross_amount' => 'decimal:2',
            'deduction_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'tax_free_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
        ];
    }
}
