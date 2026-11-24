<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SupplierPortal\Models\QualificationCriterion;

/**
 * @extends Factory<QualificationCriterion>
 */
final class QualificationCriterionFactory extends Factory
{
    protected $model = QualificationCriterion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'CRIT_'.strtoupper(fake()->unique()->lexify('????')),
            'name' => fake()->words(2, true),
            'weight' => '25.00',
            'max_score' => 5,
            'is_active' => true,
            'sort_order' => 10,
        ];
    }
}
