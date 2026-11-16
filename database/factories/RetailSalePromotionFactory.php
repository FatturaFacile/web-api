<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PosSale;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\FiscalRetail\Models\RetailSalePromotion;

/**
 * @extends Factory<RetailSalePromotion>
 */
final class RetailSalePromotionFactory extends Factory
{
    protected $model = RetailSalePromotion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'pos_sale_id' => PosSale::factory(),
            'promotion_id' => null,
            'code' => 'PROMO'.fake()->unique()->numerify('###'),
            'name' => fake()->words(2, true),
            'discount_net' => '5.00',
        ];
    }
}
