<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Childcare\Models\Activity;

/**
 * @extends Factory<Activity>
 */
final class ChildcareActivityFactory extends Factory
{
    protected $model = Activity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->randomElement(['Musica e movimento', 'Inglese per piccoli', 'Psicomotricità', 'Laboratorio creativo']),
            'weekday' => fake()->numberBetween(1, 5),
            'starts_at' => '15:00',
            'ends_at' => '16:00',
            'capacity' => fake()->numberBetween(8, 15),
            'monthly_fee' => fake()->randomElement(['40.00', '55.00', '60.00']),
            'tax_rate' => '0.00',
        ];
    }
}
