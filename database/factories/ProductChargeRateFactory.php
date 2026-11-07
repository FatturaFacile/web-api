<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\ProductChargeRate;

/**
 * @extends Factory<ProductChargeRate>
 */
final class ProductChargeRateFactory extends Factory
{
    protected $model = ProductChargeRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'product_charge_type_id' => static fn (array $attributes): string => (string) ProductChargeTypeFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'rate' => '0.660000',
            'currency' => 'EUR',
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ];
    }
}
