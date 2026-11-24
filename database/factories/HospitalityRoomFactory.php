<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Hospitality\Models\Room;

/**
 * @extends Factory<Room>
 */
final class HospitalityRoomFactory extends Factory
{
    protected $model = Room::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->randomElement(['Sala principale', 'Terrazza', 'Veranda', 'Sala camino']),
            'sort_order' => fake()->numberBetween(0, 5),
            'width' => 100,
            'height' => 100,
        ];
    }
}
