<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Sustainability\Models\EsgKpiDefinition;

/**
 * @extends Factory<EsgKpiDefinition>
 */
final class EsgKpiDefinitionFactory extends Factory
{
    protected $model = EsgKpiDefinition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'kpi_'.fake()->unique()->bothify('????_####'),
            'name' => fake()->words(3, true),
            'pillar' => 'environmental',
            'unit' => 'MWh',
            'direction' => 'lower_is_better',
            'is_example' => false,
            'is_active' => true,
        ];
    }
}
