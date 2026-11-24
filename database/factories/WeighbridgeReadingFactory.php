<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\WeighbridgeReading;

/**
 * @extends Factory<WeighbridgeReading>
 */
final class WeighbridgeReadingFactory extends Factory
{
    protected $model = WeighbridgeReading::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'device_code' => 'PESA-01',
            'weight_kg' => fake()->randomFloat(3, 12000, 44000),
            'is_stable' => true,
            'read_at' => '2026-11-04 08:20:00',
        ];
    }
}
