<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Childcare\Models\Group;

/**
 * @extends Factory<Group>
 */
final class ChildcareGroupFactory extends Factory
{
    protected $model = Group::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->randomElement(['Coccinelle', 'Farfalle', 'Scoiattoli', 'Pulcini', 'Delfini']),
            'capacity' => fake()->numberBetween(10, 24),
        ];
    }
}
