<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Facility\Models\FacilityBuilding;

/**
 * @extends Factory<FacilityBuilding>
 */
final class FacilityBuildingFactory extends Factory
{
    protected $model = FacilityBuilding::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => 'B'.fake()->unique()->numerify('####'),
            'name' => fake()->randomElement(['Sede centrale', 'Magazzino nord', 'Filiale Lugano', 'Uffici Milano']).' '.fake()->numerify('##'),
            'city' => fake()->randomElement(['Milano', 'Torino', 'Lugano', 'Zurigo']),
            'country' => fake()->randomElement(['IT', 'CH']),
            'is_active' => true,
        ];
    }
}
