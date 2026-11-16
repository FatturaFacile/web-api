<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\FiscalRetail\Models\RetailPromotion;

/**
 * @extends Factory<RetailPromotion>
 */
final class RetailPromotionFactory extends Factory
{
    protected $model = RetailPromotion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => 'PROMO'.fake()->unique()->numerify('####'),
            'name' => fake()->words(3, true),
            'type' => 'percentage',
            'value' => '10.0000',
            'is_active' => true,
            'is_stackable' => false,
            'priority' => 0,
        ];
    }
}
