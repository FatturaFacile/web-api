<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Hospitality\Models\DiningTable;

/**
 * @extends Factory<DiningTable>
 */
final class HospitalityDiningTableFactory extends Factory
{
    protected $model = DiningTable::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'room_id' => static fn (array $attributes): string => (string) HospitalityRoomFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'name' => 'T'.fake()->unique()->numberBetween(1, 99999),
            'seats' => fake()->randomElement([2, 4, 6, 8]),
            'shape' => fake()->randomElement(['square', 'round', 'rectangle']),
            'pos_x' => fake()->numberBetween(0, 90),
            'pos_y' => fake()->numberBetween(0, 90),
            'width' => 10,
            'height' => 10,
            'is_active' => true,
        ];
    }
}
