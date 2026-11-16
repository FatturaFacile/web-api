<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Governance\Models\GovFinding;

/**
 * @extends Factory<GovFinding>
 */
final class GovFindingFactory extends Factory
{
    protected $model = GovFinding::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => 'FND-'.fake()->year().'-'.fake()->unique()->numerify('####'),
            'source' => 'self_assessment',
            'title' => fake()->sentence(4),
            'severity' => fake()->randomElement(GovFinding::Severities),
            'status' => 'open',
        ];
    }
}
