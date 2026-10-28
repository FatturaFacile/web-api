<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockItemCategory;

/**
 * @extends Factory<StockItem>
 */
class StockItemFactory extends Factory
{
    protected $model = StockItem::class;

    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? (string) Str::uuid();
        $cost = fake()->randomFloat(2, 10, 500);

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'category_id' => StockItemCategory::factory(),
            'sku' => 'SKU-'.strtoupper(fake()->unique()->bothify('??-####')),
            'name' => fake()->words(3, true),
            'uom' => 'PCE',
            'description' => fake()->sentence(),
            'barcode_ean13' => fake()->ean13(),
            'tracking_type' => 'none',
            'standard_cost' => $cost,
            'current_moving_average_cost' => $cost,
            'weight_kg' => fake()->randomFloat(2, 0.5, 20),
            'is_active' => true,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function serialized(): static
    {
        return $this->state(fn () => ['tracking_type' => 'serial']);
    }

    public function lotTracked(): static
    {
        return $this->state(fn () => ['tracking_type' => 'lot']);
    }
}
