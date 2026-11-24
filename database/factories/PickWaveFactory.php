<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\PickWave;

/**
 * A released picking wave of a warehouse (the staging bin is set by the tests that need one).
 *
 * @extends Factory<PickWave>
 */
final class PickWaveFactory extends Factory
{
    protected $model = PickWave::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'warehouse_id' => static fn (array $attributes): string => (string) WarehouseFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'wave_number' => 'WAVE-'.fake()->unique()->numerify('######'),
            'status' => 'released',
            'released_at' => now(),
        ];
    }
}
