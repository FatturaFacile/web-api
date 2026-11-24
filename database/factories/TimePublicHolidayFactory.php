<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\TimePublicHoliday;

/**
 * @extends Factory<TimePublicHoliday>
 */
final class TimePublicHolidayFactory extends Factory
{
    protected $model = TimePublicHoliday::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $holiday = fake()->randomElement([
            ['2027-03-19', 'San Giuseppe'],
            ['2027-06-29', 'Santi Pietro e Paolo'],
            ['2027-12-08', 'Immacolata Concezione'],
        ]);

        return [
            'tenant_id' => Tenant::factory(),
            'canton' => 'TI',
            'holiday_date' => $holiday[0],
            'name' => $holiday[1],
            'day_fraction' => '1.00',
        ];
    }

    /**
     * Shared by every company.
     */
    public function generic(): static
    {
        return $this->state(['tenant_id' => null])->afterMaking(static fn ($model) => $model->declareGlobal());
    }
}
