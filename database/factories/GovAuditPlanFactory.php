<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Governance\Models\GovAuditPlan;

/**
 * @extends Factory<GovAuditPlan>
 */
final class GovAuditPlanFactory extends Factory
{
    protected $model = GovAuditPlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'year' => fake()->unique()->numberBetween(2030, 2090),
            'title' => 'Internal audit plan '.fake()->year(),
            'status' => 'draft',
        ];
    }
}
