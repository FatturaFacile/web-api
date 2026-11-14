<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Governance\Models\GovControl;

/**
 * @extends Factory<GovControl>
 */
final class GovControlFactory extends Factory
{
    protected $model = GovControl::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => 'CTL-'.fake()->year().'-'.fake()->unique()->numerify('####'),
            'title' => fake()->sentence(4),
            'control_type' => fake()->randomElement(GovControl::Types),
            'frequency' => fake()->randomElement(GovControl::Frequencies),
            'status' => 'draft',
        ];
    }
}
