<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Workshop\Models\WorkshopBay;

/**
 * @extends Factory<WorkshopBay>
 */
final class WorkshopBayFactory extends Factory
{
    protected $model = WorkshopBay::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => strtoupper(fake()->unique()->lexify('BAY-???')),
            'name' => fake()->words(2, true),
            'is_active' => true,
        ];
    }
}
