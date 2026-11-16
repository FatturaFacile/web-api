<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Configurator\Models\ConfigurableModel;
use Modules\Configurator\Models\ConfiguratorRule;

/**
 * @extends Factory<ConfiguratorRule>
 */
final class ConfiguratorRuleFactory extends Factory
{
    protected $model = ConfiguratorRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'model_id' => ConfigurableModel::factory(),
            'name' => fake()->sentence(3),
            'type' => ConfiguratorRule::Requires,
            'match' => 'all',
            'conditions' => [],
            'target_options' => [],
            'priority' => 100,
            'is_active' => true,
        ];
    }
}
