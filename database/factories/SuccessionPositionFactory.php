<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\TalentLearning\Models\SuccessionPosition;

/**
 * @extends Factory<SuccessionPosition>
 */
final class SuccessionPositionFactory extends Factory
{
    protected $model = SuccessionPosition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'title' => fake()->jobTitle(),
            'criticality' => 'high',
            'vacancy_risk' => 'medium',
            'status' => SuccessionPosition::Active,
        ];
    }
}
