<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\TimeScheduleGroup;

/**
 * @extends Factory<TimeScheduleGroup>
 */
final class TimeScheduleGroupFactory extends Factory
{
    protected $model = TimeScheduleGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->randomElement(['Uffici', 'Produzione a turni', 'Magazzino', 'Reparto vendite']).' '.fake()->unique()->numerify('###'),
            'is_active' => true,
        ];
    }
}
