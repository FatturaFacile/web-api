<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ConstructionTrades\Models\WorkCycle;

/**
 * @extends Factory<WorkCycle>
 */
final class TradesWorkCycleFactory extends Factory
{
    protected $model = WorkCycle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->randomElement(['Ciclo pareti interne', 'Ciclo facciata silossanica', 'Ciclo serramenti legno']),
            'unit' => 'm2',
            'descriptions' => [
                'it' => 'Fondo isolante e due mani di idropittura lavabile',
                'de' => 'Grundierung und zwei Anstriche mit abwaschbarer Dispersionsfarbe',
            ],
            'is_active' => true,
        ];
    }
}
