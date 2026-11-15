<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Warehouse;
use Modules\Wms\Models\WmsWave;

/**
 * @extends Factory<WmsWave>
 */
final class WmsWaveFactory extends Factory
{
    protected $model = WmsWave::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'number' => 'WV-2027-'.fake()->unique()->numerify('####'),
            'status' => 'planned',
            'task_count' => 0,
        ];
    }
}
