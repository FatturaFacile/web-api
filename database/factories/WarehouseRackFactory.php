<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\WarehouseAisle;
use Modules\Inventory\Models\WarehouseRack;

/**
 * @extends Factory<WarehouseRack>
 */
class WarehouseRackFactory extends Factory
{
    protected $model = WarehouseRack::class;

    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? (string) Str::uuid7();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'warehouse_aisle_id' => WarehouseAisle::factory(),
            'code' => 'R'.fake()->unique()->numerify('##'),
        ];
    }
}
