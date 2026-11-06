<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Enums\FeeFrequency;
use Modules\Associations\Models\FeePlan;

/**
 * @extends Factory<FeePlan>
 */
class FeePlanFactory extends Factory
{
    protected $model = FeePlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'F'.fake()->unique()->numberBetween(1000, 999999),
            'name' => 'Annual fee',
            'frequency' => FeeFrequency::OneOff,
            'amount' => '100.00',
        ];
    }
}
