<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Warehouse;
use Modules\Wms\Models\WmsTask;

/**
 * @extends Factory<WmsTask>
 */
final class WmsTaskFactory extends Factory
{
    protected $model = WmsTask::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'task_type' => 'putaway',
            'status' => 'open',
            'priority' => 50,
            'planned_quantity' => '1.00',
            'confirmed_quantity' => '0',
            'skip_count' => 0,
        ];
    }
}
