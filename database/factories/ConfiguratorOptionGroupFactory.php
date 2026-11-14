<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Configurator\Models\ConfigurableModel;
use Modules\Configurator\Models\ConfiguratorOptionGroup;

/**
 * @extends Factory<ConfiguratorOptionGroup>
 */
final class ConfiguratorOptionGroupFactory extends Factory
{
    protected $model = ConfiguratorOptionGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'model_id' => ConfigurableModel::factory(),
            'code' => 'GRP-'.fake()->unique()->numerify('#####'),
            'name' => fake()->word(),
            'type' => ConfiguratorOptionGroup::Single,
            'is_required' => false,
            'unit_price_delta' => '0',
            'unit_cost' => '0',
            'sort_order' => 0,
        ];
    }

    public function range(string $min, string $max, string $unitPrice): self
    {
        return $this->state(fn (): array => ['type' => ConfiguratorOptionGroup::Range, 'range_min' => $min, 'range_max' => $max, 'unit_price_delta' => $unitPrice]);
    }
}
