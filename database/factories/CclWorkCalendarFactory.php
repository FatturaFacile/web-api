<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Payroll\Models\CclWorkCalendar;

/**
 * @extends Factory<CclWorkCalendar>
 */
final class CclWorkCalendarFactory extends Factory
{
    protected $model = CclWorkCalendar::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'agreement_code' => 'CNM',
            'year' => 2027,
            'month' => fake()->numberBetween(1, 12),
            'planned_hours' => fake()->randomElement(['164.00', '172.00', '180.00', '188.00']),
        ];
    }
}
