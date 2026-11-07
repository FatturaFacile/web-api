<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Sponsor;

/**
 * @extends Factory<Sponsor>
 */
final class SponsorFactory extends Factory
{
    protected $model = Sponsor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'contact_id' => null,
            'name' => fake()->company(),
            'vat_number' => 'IT'.fake()->numerify('###########'),
            'email' => fake()->companyEmail(),
            'notes' => null,
        ];
    }
}
