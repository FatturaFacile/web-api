<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierCatalog;

/**
 * @extends Factory<SupplierCatalog>
 */
class SupplierCatalogFactory extends Factory
{
    protected $model = SupplierCatalog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? Tenant::create([
            'id' => (string) Str::uuid(),
            'name' => fake()->company(),
            'legal_name' => fake()->company(),
            'country' => 'IT',
            'default_currency' => 'EUR',
        ])->id;

        $supplier = Supplier::first() ?? Supplier::factory()->create(['tenant_id' => $tenantId]);

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'supplier_id' => $supplier->id,
            'supplier_part_number' => 'PART-'.fake()->unique()->numerify('#####'),
            'internal_sku' => 'SKU-'.fake()->unique()->numerify('#####'),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'uom' => fake()->randomElement(['PCS', 'KG', 'MTR', 'BOX', 'LTR']),
            'lead_time_days' => fake()->numberBetween(2, 30),
            'minimum_order_quantity' => fake()->randomElement([1.0, 5.0, 10.0, 50.0]),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
