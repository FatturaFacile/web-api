<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Warehouse;
use Modules\Wms\Models\WmsSlottingRule;

/**
 * @extends Factory<WmsSlottingRule>
 */
final class WmsSlottingRuleFactory extends Factory
{
    protected $model = WmsSlottingRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'name' => fake()->unique()->bothify('??-####'),
            'target_bin_type' => 'storage',
            'priority' => 100,
            'is_active' => true,
        ];
    }
}
