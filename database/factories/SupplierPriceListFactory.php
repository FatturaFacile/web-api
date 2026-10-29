<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Procurement\Models\SupplierCatalog;
use Modules\Procurement\Models\SupplierPriceList;

/**
 * @extends Factory<SupplierPriceList>
 */
class SupplierPriceListFactory extends Factory
{
    protected $model = SupplierPriceList::class;

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

        $catalog = SupplierCatalog::first() ?? SupplierCatalog::factory()->create(['tenant_id' => $tenantId]);

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'supplier_catalog_id' => $catalog->id,
            'currency' => 'EUR',
            'min_quantity' => 1.0,
            'unit_price' => fake()->randomFloat(2, 5, 250),
            'valid_from' => now()->startOfYear()->toDateString(),
            'valid_to' => now()->endOfYear()->toDateString(),
        ];
    }

    public function tiered(float $minQuantity, float $unitPrice): static
    {
        return $this->state(fn (array $attributes) => [
            'min_quantity' => $minQuantity,
            'unit_price' => $unitPrice,
        ]);
    }
}
