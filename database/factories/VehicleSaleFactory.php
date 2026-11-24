<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Workshop\Models\VehicleSale;
use Modules\Workshop\Models\VehicleStockUnit;

/**
 * @extends Factory<VehicleSale>
 */
final class VehicleSaleFactory extends Factory
{
    protected $model = VehicleSale::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'stock_id' => static fn (array $attributes): string => (string) VehicleStockUnit::factory()->create(['tenant_id' => $attributes['tenant_id'], 'status' => 'sold'])->id,
            'buyer_contact_id' => static fn (array $attributes): string => (string) Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'vehicle_id' => static fn (array $attributes): string => (string) Vehicle::factory()->create(['tenant_id' => $attributes['tenant_id'], 'client_id' => $attributes['buyer_contact_id']])->id,
            'sale_date' => fake()->dateTimeBetween('2026-01-01', '2026-12-31')->format('Y-m-d'),
            'sale_price' => static fn (array $attributes): string => (string) VehicleStockUnit::query()->withoutGlobalScopes()->findOrFail($attributes['stock_id'])->list_price,
            'tax_rate' => '8.10',
        ];
    }
}
