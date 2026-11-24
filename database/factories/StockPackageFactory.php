<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\StockPackage;
use Modules\Inventory\Models\StockPackingList;
use Modules\Inventory\Models\StockPickList;

/**
 * @extends Factory<StockPackage>
 */
final class StockPackageFactory extends Factory
{
    protected $model = StockPackage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'stock_packing_list_id' => static function (array $attributes): string {
                $pickList = StockPickList::create([
                    'tenant_id' => $attributes['tenant_id'],
                    'warehouse_id' => WarehouseFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
                    'pick_list_number' => strtoupper(fake()->unique()->bothify('PCK-2026-#####')),
                    'status' => 'completed',
                ]);

                return (string) StockPackingList::create([
                    'tenant_id' => $attributes['tenant_id'],
                    'stock_pick_list_id' => $pickList->id,
                    'packing_number' => strtoupper(fake()->unique()->bothify('PKL-2026-#####')),
                    'total_packages' => 1,
                    'packed_at' => '2026-11-02 15:00:00',
                ])->id;
            },
            'parent_package_id' => null,
            'package_number' => strtoupper(fake()->unique()->bothify('COLLO-######')),
            'package_type' => 'carton',
            'net_weight_kg' => '11.500',
            'tare_weight_kg' => '0.600',
            'gross_weight_kg' => '12.100',
        ];
    }
}
