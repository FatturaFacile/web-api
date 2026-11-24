<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\PutawayRule;

/**
 * A generic putaway rule of a warehouse: every item to storage bins, consolidating with the stock already there.
 *
 * @extends Factory<PutawayRule>
 */
final class PutawayRuleFactory extends Factory
{
    protected $model = PutawayRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'warehouse_id' => static fn (array $attributes): string => (string) WarehouseFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'stock_item_id' => null,
            'category_id' => null,
            'target_zone_id' => null,
            'target_bin_id' => null,
            'bin_type' => 'storage',
            'strategy' => 'consolidate',
            'priority' => 100,
            'is_active' => true,
        ];
    }
}
