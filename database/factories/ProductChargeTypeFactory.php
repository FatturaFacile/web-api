<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\ProductChargeType;

/**
 * @extends Factory<ProductChargeType>
 */
final class ProductChargeTypeFactory extends Factory
{
    protected $model = ProductChargeType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => strtoupper(fake()->unique()->bothify('CONAI-####')),
            'name' => 'Contributo ambientale CONAI - imballaggi in plastica',
            'calculation_basis' => 'per_kg',
            'revenue_account_id' => null,
            'is_active' => true,
        ];
    }

    /**
     * A reference charge type shared by every tenant.
     */
    public function generic(): self
    {
        return $this->state(['tenant_id' => null]);
    }
}
