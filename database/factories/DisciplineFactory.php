<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Discipline;

/**
 * @extends Factory<Discipline>
 */
final class DisciplineFactory extends Factory
{
    protected $model = Discipline::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['Nuoto', 'Pallavolo', 'Calcio a 5', 'Ginnastica artistica', 'Judo', 'Tennis']);

        return [
            'tenant_id' => Tenant::factory(),
            'code' => strtoupper(fake()->unique()->bothify('DSC-####')),
            'name' => $name,
            'is_active' => true,
        ];
    }

    /**
     * A row of the shared catalogue, visible to every company.
     */
    public function generic(): static
    {
        return $this->state(['tenant_id' => null]);
    }
}
