<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Configurator\Models\ConfigurableModel;
use Modules\Configurator\Models\ConfiguratorOption;
use Modules\Configurator\Models\ConfiguratorOptionGroup;

/**
 * @extends Factory<ConfiguratorOption>
 */
final class ConfiguratorOptionFactory extends Factory
{
    protected $model = ConfiguratorOption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'model_id' => ConfigurableModel::factory(),
            'group_id' => ConfiguratorOptionGroup::factory(),
            'code' => 'OPT-'.fake()->unique()->numerify('#####'),
            'name' => fake()->word(),
            'price_delta' => '0',
            'price_multiplier' => '1',
            'cost' => '0',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
