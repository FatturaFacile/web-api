<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\FiscalRetail\Models\RetailReturn;
use Modules\FiscalRetail\Models\RetailReturnLine;

/**
 * @extends Factory<RetailReturnLine>
 */
final class RetailReturnLineFactory extends Factory
{
    protected $model = RetailReturnLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'retail_return_id' => RetailReturn::factory(),
            'pos_sale_item_id' => (string) fake()->uuid(),
            'description' => fake()->words(2, true),
            'quantity' => '1.0000',
            'tax_rate' => '22.00',
            'net_amount' => '10.00',
            'tax_amount' => '2.20',
            'gross_amount' => '12.20',
        ];
    }
}
