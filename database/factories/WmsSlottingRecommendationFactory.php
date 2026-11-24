<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Warehouse;
use Modules\Wms\Models\WmsSlottingRecommendation;

/**
 * @extends Factory<WmsSlottingRecommendation>
 */
final class WmsSlottingRecommendationFactory extends Factory
{
    protected $model = WmsSlottingRecommendation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'stock_item_id' => Str::uuid7()->toString(),
            'abc_class' => 'A',
            'velocity' => '0',
            'period_days' => 30,
            'to_bin_id' => Str::uuid7()->toString(),
            'target_bin_type' => fake()->unique()->bothify('??-####'),
            'status' => 'pending',
        ];
    }
}
