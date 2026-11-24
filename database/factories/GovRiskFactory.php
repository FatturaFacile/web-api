<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Governance\Models\GovRisk;

/**
 * @extends Factory<GovRisk>
 */
final class GovRiskFactory extends Factory
{
    protected $model = GovRisk::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => 'RSK-'.fake()->year().'-'.fake()->unique()->numerify('####'),
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(),
            'category' => fake()->randomElement(GovRisk::Categories),
            'likelihood' => 3,
            'impact' => 3,
            'inherent_score' => 9,
            'status' => 'identified',
        ];
    }
}
