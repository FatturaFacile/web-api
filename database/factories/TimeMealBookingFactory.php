<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\TimeMealBooking;
use Modules\HumanResources\Services\TimeTracking\TimeRecordSource;

/**
 * @extends Factory<TimeMealBooking>
 */
final class TimeMealBookingFactory extends Factory
{
    protected $model = TimeMealBooking::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $mealDate = fake()->dateTimeBetween('2026-11-02', '2026-11-30')->format('Y-m-d');

        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'meal_date' => $mealDate,
            'meal_type' => 'lunch',
            'occurred_at' => $mealDate.' 12:05:00',
            'source' => TimeRecordSource::Badge,
            'note' => null,
            'created_by' => null,
        ];
    }
}
