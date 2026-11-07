<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\ProductChargeAssignment;

/**
 * @extends Factory<ProductChargeAssignment>
 */
final class ProductChargeAssignmentFactory extends Factory
{
    protected $model = ProductChargeAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'product_charge_type_id' => static fn (array $attributes): string => (string) ProductChargeTypeFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'product_id' => static fn (array $attributes): string => (string) Product::create([
                'tenant_id' => $attributes['tenant_id'],
                'sku' => strtoupper(fake()->unique()->bothify('PRD-#####')),
                'name' => 'Flacone detergente 1 L',
                'unit_measure' => 'PZ',
                'cost_price' => '1.8500',
                'sale_price' => '3.9000',
                'tax_rate' => '22.00',
            ])->id,
            'quantity_per_unit' => '0.045000',
        ];
    }
}
