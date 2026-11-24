<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Workshop\Models\VehicleStockUnit;

/**
 * @extends Factory<VehicleStockUnit>
 */
class VehicleStockUnitFactory extends Factory
{
    protected $model = VehicleStockUnit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'stock_number' => strtoupper(fake()->unique()->bothify('VS-#####')),
            'condition' => 'used',
            'vin' => strtoupper(fake()->bothify('WVW##########????')),
            'license_plate' => strtoupper(fake()->unique()->bothify('ZH ######')),
            'make' => 'Volkswagen',
            'model' => 'Golf',
            'year' => 2021,
            'mileage_km' => 42000,
            'list_price' => '18500.00',
            'currency' => 'CHF',
            'status' => 'in_stock',
        ];
    }
}
