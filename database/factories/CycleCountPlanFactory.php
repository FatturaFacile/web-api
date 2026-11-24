<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\CycleCountPlan;

/**
 * A monthly cycle count of the class A items of a warehouse, without freeze.
 *
 * @extends Factory<CycleCountPlan>
 */
final class CycleCountPlanFactory extends Factory
{
    protected $model = CycleCountPlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'warehouse_id' => static fn (array $attributes): string => (string) WarehouseFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'name' => 'Classe A '.fake()->unique()->numerify('###'),
            'abc_class' => 'A',
            'interval_days' => 30,
            'freezes_stock' => false,
            'is_active' => true,
            'next_run_on' => null,
        ];
    }
}
