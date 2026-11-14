<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\TravelExpense\Models\TravelPerDiemRate;

/**
 * An Italian domestic rate (art. 51 TUIR thresholds); the swiss state is the flat CHF rate.
 *
 * @extends Factory<TravelPerDiemRate>
 */
final class TravelPerDiemRateFactory extends Factory
{
    protected $model = TravelPerDiemRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'jurisdiction' => 'IT',
            'year' => (int) now()->format('Y'),
            'scope' => TravelPerDiemRate::Domestic,
            'country' => null,
            'currency' => 'EUR',
            'daily_amount' => '46.48',
            'tax_free_daily_amount' => '46.48',
            'lodging_deduction_percent' => '33.3333',
            'breakfast_deduction_percent' => '0',
            'meal_deduction_percent' => '33.3333',
            'deduct_each_meal' => false,
            'half_day_min_hours' => 8,
            'full_day_min_hours' => 12,
        ];
    }

    public function swissFlat(): static
    {
        return $this->state([
            'jurisdiction' => 'CH',
            'currency' => 'CHF',
            'daily_amount' => '75.00',
            'tax_free_daily_amount' => null,
            'lodging_deduction_percent' => '0',
            'breakfast_deduction_percent' => '20',
            'meal_deduction_percent' => '40',
            'deduct_each_meal' => true,
        ]);
    }
}
