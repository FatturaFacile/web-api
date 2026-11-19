<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseZone;

/**
 * @extends Factory<WarehouseZone>
 */
class WarehouseZoneFactory extends Factory
{
    protected $model = WarehouseZone::class;

    public function definition(): array
    {
        $tenantId = (app(TenantContext::class)->getTenantId() ?? Tenant::first()?->id) ?? (string) Str::uuid7();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'warehouse_id' => Warehouse::factory(),
            'code' => 'ZONE-'.strtoupper(fake()->unique()->bothify('?#')),
            'name' => fake()->word().' Zone',
            'zone_type' => 'storage',
        ];
    }
}
