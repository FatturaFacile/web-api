<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\WarehouseAisle;
use Modules\Inventory\Models\WarehouseZone;

/**
 * @extends Factory<WarehouseAisle>
 */
class WarehouseAisleFactory extends Factory
{
    protected $model = WarehouseAisle::class;

    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? (string) Str::uuid7();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'warehouse_zone_id' => WarehouseZone::factory(),
            'code' => 'A'.fake()->unique()->numerify('##'),
        ];
    }
}
