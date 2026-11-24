<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Configurator\Models\ConfigurableModel;

/**
 * @extends Factory<ConfigurableModel>
 */
final class ConfigurableModelFactory extends Factory
{
    protected $model = ConfigurableModel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'MOD-'.fake()->unique()->numerify('#####'),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'base_price' => '1000.0000',
            'base_cost' => '600.0000',
            'currency' => 'EUR',
            'tax_rate' => '22.00',
            'status' => ConfigurableModel::Draft,
            'version' => 1,
        ];
    }

    public function published(): self
    {
        return $this->state(fn (): array => ['status' => ConfigurableModel::Published, 'published_at' => now()]);
    }
}
