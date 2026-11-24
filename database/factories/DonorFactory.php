<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Donor;

/**
 * @extends Factory<Donor>
 */
final class DonorFactory extends Factory
{
    protected $model = Donor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'kind' => 'person',
            'name' => fake()->firstName().' '.fake()->lastName(),
            'tax_code' => strtoupper(fake()->unique()->bothify('??????##?##?###?')),
            'email' => fake()->safeEmail(),
            'address' => 'Via '.fake()->lastName().' '.fake()->numberBetween(1, 120).', 20121 Milano (MI)',
        ];
    }

    public function organization(): static
    {
        return $this->state(['kind' => 'organization', 'name' => fake()->company().' S.r.l.', 'tax_code' => fake()->numerify('###########')]);
    }
}
