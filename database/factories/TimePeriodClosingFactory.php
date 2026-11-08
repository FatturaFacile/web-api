<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\TimePeriodClosing;

/**
 * @extends Factory<TimePeriodClosing>
 */
final class TimePeriodClosingFactory extends Factory
{
    protected $model = TimePeriodClosing::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'year' => 2026,
            'month' => fake()->numberBetween(1, 12),
            'status' => TimePeriodClosing::StatusClosed,
            'closed_at' => now(),
        ];
    }
}
