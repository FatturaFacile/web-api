<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Hospitality\Models\RatePlan;

/**
 * @extends Factory<RatePlan>
 */
final class HospitalityRatePlanFactory extends Factory
{
    protected $model = RatePlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => strtoupper(fake()->unique()->lexify('????')),
            'name' => fake()->words(2, true),
            'nightly_rate' => '120.00',
            'valid_from' => now()->startOfYear()->toDateString(),
            'min_stay' => 1,
            'priority' => 0,
            'is_active' => true,
        ];
    }
}
