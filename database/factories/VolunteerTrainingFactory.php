<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\Volunteer;
use Modules\Associations\Models\VolunteerTraining;

/**
 * @extends Factory<VolunteerTraining>
 */
final class VolunteerTrainingFactory extends Factory
{
    protected $model = VolunteerTraining::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'volunteer_id' => static fn (array $attributes): string => Volunteer::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'member_id' => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            ])->id,
            'title' => fake()->randomElement(['Corso BLSD', 'Primo soccorso', 'Sicurezza sui luoghi di lavoro']),
            'completed_on' => '2026-03-14',
            'expires_on' => '2028-03-14',
        ];
    }
}
