<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ConstructionTrades\Models\WorkCycleStep;

/**
 * @extends Factory<WorkCycleStep>
 */
final class TradesWorkCycleStepFactory extends Factory
{
    protected $model = WorkCycleStep::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'work_cycle_id' => static fn (array $attributes): string => (string) TradesWorkCycleFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'sort_order' => 1,
            'price_item_id' => null,
            'descriptions' => [
                'it' => 'Applicazione di fondo isolante',
                'de' => 'Grundierung auftragen',
            ],
            'quantity_factor' => '1.0000',
            'unit_price' => number_format(fake()->randomFloat(2, 3, 12), 4, '.', ''),
            'labour_minutes' => number_format(fake()->randomFloat(1, 3, 10), 2, '.', ''),
            'material_cost' => number_format(fake()->randomFloat(2, 0.5, 3), 4, '.', ''),
            'machine_cost' => '0.0000',
        ];
    }
}
