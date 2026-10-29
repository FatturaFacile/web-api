<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Models\WarehouseRack;

/**
 * @extends Factory<WarehouseBin>
 */
class WarehouseBinFactory extends Factory
{
    protected $model = WarehouseBin::class;

    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? (string) Str::uuid7();
        $code = 'BIN-'.strtoupper(fake()->unique()->bothify('?##'));

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'warehouse_rack_id' => WarehouseRack::factory(),
            'code' => $code,
            'barcode' => 'BAR-'.$code.'-'.fake()->unique()->numerify('####'),
            'max_weight_kg' => 500.0,
            'max_volume_cbm' => 2.5,
            'is_locked' => false,
        ];
    }

    public function locked(): static
    {
        return $this->state(fn () => [
            'is_locked' => true,
        ]);
    }
}
