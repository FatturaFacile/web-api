<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The daily allowance of a company for a jurisdiction (IT trasferta rules, CH Spesenreglement), year and scope
 * (domestic or abroad), optionally for one destination country.
 *
 * The deductions are percentages of the daily amount for the meals and the lodging the employer provided. With
 * deduct_each_meal false (the Italian rule) the meal deduction applies once when lunch or dinner is provided; with it
 * true (the usual Swiss flat rates) every provided meal is deducted, the breakfast with its own percentage.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $jurisdiction
 * @property int $year
 * @property string $scope
 * @property string|null $country
 * @property string $currency
 * @property string $daily_amount
 * @property string|null $tax_free_daily_amount
 * @property string $lodging_deduction_percent
 * @property string $breakfast_deduction_percent
 * @property string $meal_deduction_percent
 * @property bool $deduct_each_meal
 * @property int $half_day_min_hours
 * @property int $full_day_min_hours
 * @property string|null $notes
 */
final class TravelPerDiemRate extends BaseEnterpriseModel
{
    public const string Domestic = 'domestic';

    public const string Abroad = 'abroad';

    public const array Scopes = [self::Domestic, self::Abroad];

    protected $table = 'travel_per_diem_rates';

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'daily_amount' => 'decimal:2',
            'tax_free_daily_amount' => 'decimal:2',
            'lodging_deduction_percent' => 'decimal:4',
            'breakfast_deduction_percent' => 'decimal:4',
            'meal_deduction_percent' => 'decimal:4',
            'deduct_each_meal' => 'boolean',
            'half_day_min_hours' => 'integer',
            'full_day_min_hours' => 'integer',
        ];
    }
}
