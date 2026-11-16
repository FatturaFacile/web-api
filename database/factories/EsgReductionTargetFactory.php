<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Sustainability\Models\EsgReductionTarget;

/**
 * @extends Factory<EsgReductionTarget>
 */
final class EsgReductionTargetFactory extends Factory
{
    protected $model = EsgReductionTarget::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'name' => 'Reduce '.fake()->word().' emissions',
            'scope' => 'total',
            'baseline_year' => 2024,
            'target_year' => 2030,
            'reduction_percent' => '42.00',
            'status' => EsgReductionTarget::Draft,
        ];
    }
}
