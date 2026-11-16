<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Configurator\Models\ConfigurableModel;
use Modules\Configurator\Models\ProductConfiguration;

/**
 * @extends Factory<ProductConfiguration>
 */
final class ProductConfigurationFactory extends Factory
{
    protected $model = ProductConfiguration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'model_id' => ConfigurableModel::factory(),
            'number' => 'CFG-'.fake()->year().'-'.fake()->unique()->numerify('####'),
            'quantity' => '1',
            'discount_percent' => '0',
            'currency' => 'EUR',
            'selections' => [],
            'status' => ProductConfiguration::Draft,
        ];
    }
}
