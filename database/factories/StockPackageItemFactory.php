<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\StockPackageItem;

/**
 * @extends Factory<StockPackageItem>
 */
final class StockPackageItemFactory extends Factory
{
    protected $model = StockPackageItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'stock_package_id' => static fn (array $attributes): string => (string) StockPackageFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'stock_item_id' => static fn (array $attributes): string => (string) StockItemFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'lot_id' => null,
            'quantity' => '6.0000',
            'net_weight_kg' => '5.750',
        ];
    }
}
