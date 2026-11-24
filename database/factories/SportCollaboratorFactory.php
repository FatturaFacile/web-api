<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\SportCollaborator;

/**
 * @extends Factory<SportCollaborator>
 */
final class SportCollaboratorFactory extends Factory
{
    protected $model = SportCollaborator::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'member_id' => static fn (array $attributes): string => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'role' => fake()->randomElement(['Istruttore', 'Allenatore', 'Preparatore atletico']),
            'qualification' => 'Istruttore di primo livello',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
            'insurer' => 'Mutua sportiva',
            'insurance_policy' => fake()->bothify('POL-#####'),
            'insurance_expires_on' => '2027-08-31',
        ];
    }
}
