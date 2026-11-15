<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Transportation\Models\TmsTransportOrder;

/**
 * @extends Factory<TmsTransportOrder>
 */
final class TmsTransportOrderFactory extends Factory
{
    protected $model = TmsTransportOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'number' => 'TO-2027-'.fake()->unique()->numerify('####'),
            'status' => 'draft',
            'origin_name' => fake()->unique()->bothify('??-####'),
            'origin_location' => fake()->unique()->bothify('??-####'),
            'destination_name' => fake()->unique()->bothify('??-####'),
            'destination_location' => fake()->unique()->bothify('??-####'),
            'weight_kg' => '100.000',
            'volume_cbm' => '0',
            'pallets' => 0,
            'currency' => 'EUR',
        ];
    }
}
