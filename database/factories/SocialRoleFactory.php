<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\SocialRole;

/**
 * @extends Factory<SocialRole>
 */
final class SocialRoleFactory extends Factory
{
    protected $model = SocialRole::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => strtoupper(fake()->unique()->bothify('ROLE-####')),
            'name' => fake()->randomElement(['Presidente', 'Vicepresidente', 'Segretario', 'Tesoriere', 'Consigliere']),
            'is_single_holder' => false,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 50),
        ];
    }

    /**
     * A role shared by every company of the installation.
     */
    public function generic(): static
    {
        return $this->state(['tenant_id' => null]);
    }
}
