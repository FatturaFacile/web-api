<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PosSale;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\FiscalRetail\Models\RetailSalePayment;

/**
 * @extends Factory<RetailSalePayment>
 */
final class RetailSalePaymentFactory extends Factory
{
    protected $model = RetailSalePayment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'pos_sale_id' => PosSale::factory(),
            'method' => 'cash',
            'amount' => '10.00',
            'reference' => null,
        ];
    }
}
